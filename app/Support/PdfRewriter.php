<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Réécrit un PDF « compressé » (PDF 1.5 et plus : table des objets et objets rangés
 * dans des flux compressés, comme ceux de Word ou de nombreux scanners) en PDF classique,
 * lisible par l'outil qui ajoute la page de signature. Le contenu des pages n'est pas modifié.
 */
class PdfRewriter
{
    private string $pdf;

    /** @var array<int, array{0: int, 1: int, 2: int}> numéro => [type, champ 2, champ 3] (type 1 : position ; type 2 : flux, index) */
    private array $entries = [];

    /** @var list<int> positions des tables (pour délimiter les objets) */
    private array $sections = [];

    private ?string $root = null;

    private ?string $info = null;

    /** @throws InvalidArgumentException PDF protégé ou illisible */
    public static function toClassic(string $pdf): string
    {
        return (new self)->rewrite($pdf);
    }

    private function rewrite(string $pdf): string
    {
        $this->pdf = $pdf;
        if (! preg_match_all('/startxref\s+(\d+)/', $pdf, $m)) {
            throw new InvalidArgumentException('Table des objets introuvable.');
        }
        $this->readSection((int) end($m[1]), []);
        if (! $this->root) {
            throw new InvalidArgumentException('Catalogue introuvable.');
        }

        $objects = [];
        $streams = [];
        $positions = $this->positions();
        foreach ($this->entries as $number => [$type, $a, $b]) {
            if ($type === 1) {
                $body = $this->sliceObject($a, $positions);
                if ($body !== null && ! preg_match('#/Type\s*/(ObjStm|XRef)\b#', substr($body, 0, 300))) {
                    $objects[$number] = [$b, $body];
                }
            } elseif ($type === 2) {
                $streams[$a] ??= $this->objectStream($a, $positions);
                if (isset($streams[$a][$b])) {
                    $objects[$number] = [0, $streams[$a][$b]];
                }
            }
        }
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => [$generation, $body]) {
            $offsets[$number] = [strlen($out), $generation];
            $out .= "$number $generation obj\n".trim($body)."\nendobj\n";
        }
        $size = max(array_keys($objects) ?: [0]) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 $size\n";
        for ($i = 0; $i < $size; $i++) {
            $out .= isset($offsets[$i])
                ? sprintf("%010d %05d n \n", $offsets[$i][0], $offsets[$i][1])
                : '0000000000 '.($i === 0 ? '65535' : '00000')." f \n";
        }

        return $out."trailer\n<< /Size $size /Root {$this->root}".($this->info ? " /Info {$this->info}" : '')." >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** Lit une table (classique ou compressée) puis les précédentes ; les plus récentes l'emportent. */
    private function readSection(int $offset, array $seen): void
    {
        if (in_array($offset, $seen, true) || $offset <= 0 || $offset >= strlen($this->pdf) || count($seen) > 50) {
            return;
        }
        $seen[] = $offset;
        $this->sections[] = $offset;
        $head = substr($this->pdf, $offset, 20);

        if (preg_match('/^\s*xref/', $head)) {
            $trailerAt = strpos($this->pdf, 'trailer', $offset);
            if ($trailerAt === false) {
                throw new InvalidArgumentException('Table illisible.');
            }
            $table = substr($this->pdf, $offset, $trailerAt - $offset);
            preg_match_all('/(\d+)\s+(\d+)\s*[\r\n]+((?:\d{10}\s\d{5}\s[nf]\s*[\r\n]*)+)/', $table, $sub, PREG_SET_ORDER);
            foreach ($sub as [, $start, $count, $rows]) {
                preg_match_all('/(\d{10})\s(\d{5})\s([nf])/', $rows, $lines, PREG_SET_ORDER);
                foreach (array_slice($lines, 0, (int) $count) as $i => [, $pos, $gen, $flag]) {
                    $this->entries[(int) $start + $i] ??= $flag === 'n' ? [1, (int) $pos, (int) $gen] : [0, 0, 0];
                }
            }
            $dict = $this->dictionaryAt($trailerAt);
            $this->readTrailer($dict);
            if (preg_match('#/XRefStm\s+(\d+)#', $dict, $x)) {
                $this->readSection((int) $x[1], $seen);
            }
        } else {
            [$dict, $data] = $this->streamAt($offset);
            if (! preg_match('#/Type\s*/XRef\b#', $dict)) {
                throw new InvalidArgumentException('Table illisible.');
            }
            $this->readTrailer($dict);
            $this->readXrefStream($dict, $data);
        }

        if (preg_match('#/Prev\s+(\d+)#', $dict ?? '', $p)) {
            $this->readSection((int) $p[1], $seen);
        }
    }

    private function readTrailer(string $dict): void
    {
        if (preg_match('#/Encrypt\b#', $dict)) {
            throw new InvalidArgumentException('PDF protégé.');
        }
        if (! $this->root && preg_match('#/Root\s+(\d+\s+\d+\s+R)#', $dict, $r)) {
            $this->root = $r[1];
        }
        if (! $this->info && preg_match('#/Info\s+(\d+\s+\d+\s+R)#', $dict, $i)) {
            $this->info = $i[1];
        }
    }

    private function readXrefStream(string $dict, string $data): void
    {
        if (! preg_match('#/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s*\]#', $dict, $w) || ! preg_match('#/Size\s+(\d+)#', $dict, $size)) {
            throw new InvalidArgumentException('Table illisible.');
        }
        $widths = [(int) $w[1], (int) $w[2], (int) $w[3]];
        $row = array_sum($widths);
        $index = preg_match('#/Index\s*\[([\d\s]+)\]#', $dict, $ix)
            ? array_map('intval', preg_split('/\s+/', trim($ix[1])))
            : [0, (int) $size[1]];

        $position = 0;
        for ($s = 0; $s + 1 < count($index); $s += 2) {
            for ($n = 0; $n < $index[$s + 1]; $n++, $position += $row) {
                if ($position + $row > strlen($data)) {
                    return;
                }
                $fields = [];
                $cursor = $position;
                foreach ($widths as $k => $width) {
                    $value = $width === 0 ? ($k === 0 ? 1 : 0) : 0;
                    for ($b = 0; $b < $width; $b++) {
                        $value = ($value << 8) | ord($data[$cursor++]);
                    }
                    $fields[] = $value;
                }
                $this->entries[$index[$s] + $n] ??= $fields;
            }
        }
    }

    /** @return array<int, string> numéro d'ordre dans le flux => contenu de l'objet */
    private function objectStream(int $number, array $positions): array
    {
        $entry = $this->entries[$number] ?? null;
        if (! $entry || $entry[0] !== 1) {
            return [];
        }
        [$dict, $data] = $this->streamAt($entry[1]);
        if (! preg_match('#/N\s+(\d+)#', $dict, $n) || ! preg_match('#/First\s+(\d+)#', $dict, $first)) {
            return [];
        }
        $pairs = array_map('intval', preg_split('/\s+/', trim(substr($data, 0, (int) $first[1]))));
        $objects = [];
        for ($i = 0; $i < (int) $n[1]; $i++) {
            $start = (int) $first[1] + ($pairs[$i * 2 + 1] ?? 0);
            $end = isset($pairs[$i * 2 + 3]) ? (int) $first[1] + $pairs[$i * 2 + 3] : strlen($data);
            $objects[$i] = substr($data, $start, $end - $start);
        }

        return $objects;
    }

    /** Dictionnaire et données (décompressées) du flux qui commence à cette position. */
    private function streamAt(int $offset): array
    {
        $start = strpos($this->pdf, 'stream', $offset);
        $end = $start === false ? false : strpos($this->pdf, 'endstream', $start + 6);
        if ($start === false || $end === false) {
            throw new InvalidArgumentException('Flux illisible.');
        }
        $dict = substr($this->pdf, $offset, $start - $offset);
        $dataStart = $start + 6 + (substr($this->pdf, $start + 6, 2) === "\r\n" ? 2 : 1);
        $raw = substr($this->pdf, $dataStart, $end - $dataStart);
        if (preg_match('#/Length\s+(\d++)(?!\s+\d+\s+R)#', $dict, $length) && (int) $length[1] <= strlen($raw)) {
            $raw = substr($raw, 0, (int) $length[1]);
        }

        if (preg_match('#/Filter\s*\[?\s*/FlateDecode\s*\]?#', $dict)) {
            $data = @gzuncompress($raw);
            if ($data === false) {
                $context = inflate_init(ZLIB_ENCODING_DEFLATE);
                $data = $context ? @inflate_add($context, $raw, ZLIB_FINISH) : false;
            }
            if ($data === false || $data === '') {
                throw new InvalidArgumentException('Flux illisible.');
            }
        } elseif (preg_match('#/Filter#', $dict)) {
            throw new InvalidArgumentException('Compression non prise en charge.');
        } else {
            $data = $raw;
        }

        if (preg_match('#/Predictor\s+(\d+)#', $dict, $predictor) && (int) $predictor[1] >= 10) {
            $columns = preg_match('#/Columns\s+(\d+)#', $dict, $c) ? (int) $c[1] : 1;
            $data = self::unpredict($data, $columns);
        }

        return [$dict, $data];
    }

    /** Filtres PNG (ligne par ligne) utilisés par les tables compressées. */
    private static function unpredict(string $data, int $columns): string
    {
        $out = '';
        $previous = array_fill(0, $columns, 0);
        foreach (str_split($data, $columns + 1) as $line) {
            if (strlen($line) < $columns + 1) {
                break;
            }
            $filter = ord($line[0]);
            $current = [];
            for ($i = 0; $i < $columns; $i++) {
                $x = ord($line[$i + 1]);
                $left = $i > 0 ? $current[$i - 1] : 0;
                $up = $previous[$i];
                $upLeft = $i > 0 ? $previous[$i - 1] : 0;
                $current[$i] = match ($filter) {
                    1 => $x + $left,
                    2 => $x + $up,
                    3 => $x + intdiv($left + $up, 2),
                    4 => $x + self::paeth($left, $up, $upLeft),
                    default => $x,
                } & 0xFF;
            }
            $out .= pack('C*', ...$current);
            $previous = $current;
        }

        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        [$pa, $pb, $pc] = [abs($p - $a), abs($p - $b), abs($p - $c)];

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /** @return list<int> toutes les positions connues (objets et tables), triées */
    private function positions(): array
    {
        $positions = $this->sections;
        foreach ($this->entries as [$type, $offset]) {
            if ($type === 1) {
                $positions[] = $offset;
            }
        }
        $positions[] = strlen($this->pdf);
        $positions = array_values(array_unique($positions));
        sort($positions);

        return $positions;
    }

    /** Contenu de l'objet (sans « n g obj » ni « endobj »), délimité par la position suivante. */
    private function sliceObject(int $offset, array $positions): ?string
    {
        $next = strlen($this->pdf);
        foreach ($positions as $position) {
            if ($position > $offset) {
                $next = $position;
                break;
            }
        }
        $slice = substr($this->pdf, $offset, $next - $offset);
        $end = strrpos($slice, 'endobj');
        if (! preg_match('/^\s*\d+\s+\d+\s+obj\b/', $slice, $head) || $end === false) {
            return null;
        }

        return substr($slice, strlen($head[0]), $end - strlen($head[0]));
    }

    private function dictionaryAt(int $offset): string
    {
        $start = strpos($this->pdf, '<<', $offset);
        $end = strpos($this->pdf, 'startxref', $offset);

        return $start === false ? '' : substr($this->pdf, $start, ($end === false ? strlen($this->pdf) : $end) - $start);
    }
}
