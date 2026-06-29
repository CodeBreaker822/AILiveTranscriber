$(function () {
    window.requestPolishInstructions = () => {
        const dialog = document.querySelector('[data-polish-dialog]');

        if (!(dialog instanceof HTMLDialogElement)) {
            return Promise.resolve(null);
        }

        const $dialog = $(dialog);
        const $instructions = $dialog.find('[data-polish-instructions]');
        const $error = $dialog.find('[data-polish-instructions-error]');
        const $replaceWarning = $dialog.find('[data-polish-replace-warning]');
        const $confirm = $dialog.find('[data-polish-confirm]');
        const $presets = $dialog.find('[data-polish-preset]');
        const presetInstructions = {
            'translate-en': 'Translate every non-English part of the transcript into clear English. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
            'fix-grammar': 'Fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes without translating the transcript. Preserve the original language choices, meaning, names, titles, numbers, and time order.',
            'translate-en-fix-grammar': 'Translate every non-English sentence, phrase, or word into polished English, then fix grammar, spelling, punctuation, capitalization, and obvious speech-to-text mistakes. Treat Cebuano, Bisaya, Filipino, Tagalog, and mixed code-switching as source language. Do not leave source-language words untranslated unless they are names, offices, agencies, titles, acronyms, places, or proper nouns. Preserve meaning, speaker intent, numbers, and time order.',
        };
        const syncPresetState = () => {
            const currentInstructions = String($instructions.val() || '').trim();

            $presets.each(function () {
                const key = String($(this).attr('data-polish-preset') || '');
                const selected = presetInstructions[key] === currentInstructions;

                $(this)
                    .attr('aria-pressed', selected ? 'true' : 'false')
                    .toggleClass('border-cyan-300/40 bg-cyan-300/15 text-cyan-50', selected)
                    .toggleClass('border-white/10 bg-white/[0.03] text-slate-200', !selected);
            });
        };

        $instructions.val('');
        $error.addClass('hidden');
        $replaceWarning.removeClass('hidden');
        syncPresetState();
        $dialog.removeClass('hidden');
        dialog.showModal();
        window.setTimeout(() => $instructions.trigger('focus'), 0);

        return new Promise((resolve) => {
            let submitted = false;

            const finish = (value) => {
                $confirm.off('.polishInstructions');
                $instructions.off('.polishInstructions');
                $presets.off('.polishInstructions');
                dialog.removeEventListener('close', handleClose);
                $dialog.addClass('hidden');
                resolve(value);
            };
            const handleClose = () => {
                if (!submitted) {
                    finish(null);
                }
            };

            dialog.addEventListener('close', handleClose);
            $instructions.on('input.polishInstructions', () => {
                $error.addClass('hidden');
                syncPresetState();
            });
            $presets.on('click.polishInstructions', function () {
                const key = String($(this).attr('data-polish-preset') || '');
                const preset = presetInstructions[key];

                if (!preset) {
                    return;
                }

                $instructions.val(preset).trigger('input').trigger('focus');
            });
            $confirm.on('click.polishInstructions', () => {
                const instructions = String($instructions.val() || '').trim();

                if (instructions.length < 3) {
                    $error.removeClass('hidden');
                    $instructions.trigger('focus');
                    return;
                }

                submitted = true;
                dialog.close();
                finish(instructions);
            });
        });
    };
});
