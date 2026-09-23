<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\CatalogItem;
use App\Models\TextTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAndTextsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_list_is_grouped_and_searchable(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('catalog.index'))
            ->assertOk()
            ->assertSee('Nettoyage &amp; traitement de toiture', false)
            ->assertSee('Réfection d&#039;un pied de cheminée en zinc plomb', false);

        $this->actingAs($admin)->get(route('catalog.index', ['q' => 'velux']))
            ->assertSee('Remplacement d&#039;un raccord Velux', false)
            ->assertDontSee('Résine colorée');
    }

    public function test_item_can_be_created_with_french_price(): void
    {
        $category = CatalogCategory::query()->firstOrFail();

        $this->actingAs($this->admin())->post(route('catalog.store'), [
            'name' => 'Démoussage de muret',
            'category_id' => $category->id,
            'description' => "Brossage\nTraitement",
            'unit' => 'ml',
            'unit_price' => '12,50',
            'is_active' => '1',
        ])->assertRedirect(route('catalog.index'));

        $item = CatalogItem::query()->where('name', 'Démoussage de muret')->firstOrFail();
        $this->assertSame(1250, $item->unit_price);
        $this->assertNull($item->vat_rate);
        $this->assertTrue($item->is_active);
    }

    public function test_invalid_price_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('catalog.store'), ['name' => 'X', 'unit' => 'u', 'unit_price' => 'douze'])
            ->assertSessionHasErrors('unit_price');
    }

    public function test_hidden_item_is_not_offered_in_the_editor(): void
    {
        $item = CatalogItem::query()->where('name', 'Diagnostic d\'un volet roulant Velux')->firstOrFail();
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('catalog.update', $item), [
            'name' => $item->name, 'unit' => $item->unit, 'unit_price' => '19',
        ]);

        $this->assertFalse($item->fresh()->is_active);
        $this->actingAs($admin)->get(route('quotes.create'))->assertDontSee('volet roulant Velux');
    }

    public function test_item_can_be_deleted(): void
    {
        $item = CatalogItem::query()->firstOrFail();

        $this->actingAs($this->admin())->delete(route('catalog.destroy', $item));

        $this->assertModelMissing($item);
    }

    public function test_categories_can_be_added_and_renamed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('catalog.categories.store'), ['name' => 'Urgences']);
        $category = CatalogCategory::query()->where('name', 'Urgences')->firstOrFail();

        $this->actingAs($admin)->put(route('catalog.categories.update', $category), ['name' => 'Interventions d\'urgence']);

        $this->assertSame('Interventions d\'urgence', $category->fresh()->name);
    }

    public function test_text_templates_page_and_single_default_payment_terms(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('settings.texts'))->assertOk()->assertSee('Acompte 40 % / solde 60 %');

        $other = TextTemplate::query()->where('label', 'Paiement à réception')->firstOrFail();
        $this->actingAs($admin)->put(route('settings.texts.update', $other), [
            'label' => $other->label, 'body' => $other->body, 'is_default' => '1',
        ]);

        $this->assertSame(['Paiement à réception'], TextTemplate::query()->ofType('payment_terms')->where('is_default', true)->pluck('label')->all());
    }

    public function test_text_template_can_be_added_and_deleted(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('settings.texts.store'), ['type' => 'note', 'label' => 'Garantie', 'body' => 'Travaux garantis 10 ans.']);
        $template = TextTemplate::query()->where('label', 'Garantie')->firstOrFail();

        $this->actingAs($admin)->delete(route('settings.texts.destroy', $template));

        $this->assertModelMissing($template);
    }
}
