<div class="form-grid cols-2">
    <div class="field">
        <label for="{{ $prefix }}-name">Nom du modèle</label>
        <input id="{{ $prefix }}-name" type="text" name="name" value="{{ $template->name }}" required maxlength="120">
    </div>
    <div class="field">
        <label for="{{ $prefix }}-context">Utilisé pour</label>
        <select id="{{ $prefix }}-context" name="context">
            @foreach (\App\Models\EmailTemplate::CONTEXTS as $key => $label)
                <option value="{{ $key }}" @selected($template->context === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="field span-2">
        <label for="{{ $prefix }}-subject">Objet</label>
        <input id="{{ $prefix }}-subject" type="text" name="subject" value="{{ $template->subject }}" required maxlength="200">
    </div>
    <div class="field span-2">
        <label for="{{ $prefix }}-body">Message</label>
        <textarea id="{{ $prefix }}-body" name="body" rows="9" required data-autogrow>{{ $template->body }}</textarea>
    </div>
    <label class="check span-2"><input type="checkbox" name="is_default" value="1" @checked($template->is_default)> <span>Proposé en premier</span></label>
</div>
