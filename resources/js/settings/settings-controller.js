import { notify, notifyError } from '../shared/dom.js';

export const initSettingsPage = () => {
    const $speechProviderSelect = $('[data-speech-provider-select]');
    const $speechProviderPanels = $('[data-speech-provider-panel]');
    const $serverSettingsForm = $('[data-settings-form]');
    const $serverProviderSelect = $('[data-server-provider-select]');
    const $serverModelSelect = $('[data-server-model-select]');
    const $resourceMode = $('[data-resource-mode]');
    const $resourceManualInputs = $('[data-resource-manual]');
    const $resourceGpuManualInputs = $('[data-resource-gpu-manual]');
    const $resourceAutoSummary = $('[data-resource-auto-summary]');
    const syncSpeechProviderPanels = () => {
        const selectedProvider = String($speechProviderSelect.val() || 'elevenlabs');

        $speechProviderPanels.each(function () {
            const $panel = $(this);
            const isSelected = String($panel.data('speech-provider-panel') || '') === selectedProvider;

            $panel.toggleClass('hidden', !isSelected);
            $panel.find('input, select, textarea').prop('disabled', !isSelected);
        });
    };

    $speechProviderSelect.on('change', syncSpeechProviderPanels);
    syncSpeechProviderPanels();

    const syncServerModels = () => {
        if (!$serverSettingsForm.length || !$serverProviderSelect.length || !$serverModelSelect.length) {
            return;
        }

        let providers = {};

        try {
            providers = JSON.parse(String($serverSettingsForm.attr('data-provider-models') || '{}'));
        } catch (error) {
            providers = {};
        }

        const selectedProvider = String($serverProviderSelect.val() || '');
        const selectedModel = String($serverModelSelect.attr('data-selected-model') || $serverModelSelect.val() || '');
        const models = providers[selectedProvider]?.models || [];

        $serverModelSelect.empty();

        models.forEach((model) => {
            $('<option>')
                .val(String(model.id || ''))
                .text(String(model.label || model.id || ''))
                .prop('selected', String(model.id || '') === selectedModel)
                .appendTo($serverModelSelect);
        });

        if (!$serverModelSelect.val()) {
            $serverModelSelect.find('option').first().prop('selected', true);
        }
    };

    $serverProviderSelect.on('change', function () {
        $serverModelSelect.attr('data-selected-model', '');
        syncServerModels();
    });
    syncServerModels();

    const syncResourceControls = () => {
        const manual = String($resourceMode.val() || 'auto') === 'manual';
        $resourceManualInputs.prop('disabled', !manual).toggleClass('opacity-60', !manual);
        $resourceAutoSummary.toggleClass('hidden', manual);
        $resourceGpuManualInputs.each(function () {
            const $input = $(this);
            const enabled = manual && String($input.attr('data-gpu-available') || 'false') === 'true';

            $input.prop('disabled', !enabled).toggleClass('opacity-60', !enabled);
        });
    };

    $resourceMode.on('change', syncResourceControls);
    syncResourceControls();

    const refreshSettingsUi = (payload = {}) => {
        const data = payload.data || {};

        if (data.provider_payload && $serverSettingsForm.length) {
            $serverSettingsForm.attr('data-provider-models', JSON.stringify(data.provider_payload));
        }

        if (Array.isArray(data.transcription_providers) && $serverProviderSelect.length) {
            const selectedProvider = String(data.selected_provider || '');

            $serverProviderSelect.empty();
            data.transcription_providers.forEach((provider) => {
                $('<option>')
                    .val(String(provider.provider || ''))
                    .text(String(provider.name || provider.provider || ''))
                    .prop('selected', String(provider.provider || '') === selectedProvider)
                    .appendTo($serverProviderSelect);
            });
        }

        if (data.selected_model && $serverModelSelect.length) {
            $serverModelSelect.attr('data-selected-model', String(data.selected_model || ''));
        }

        syncServerModels();

        if (data.selected_model && $serverModelSelect.length) {
            $serverModelSelect.val(String(data.selected_model || ''));
        }

        if (data.license_status_label) {
            $('[data-settings-license-status-label]').text(String(data.license_status_label));
        }

        if (data.license_status_message) {
            $('[data-settings-license-status-message]').text(String(data.license_status_message));
        }

        if (data.resource_profile) {
            $('body')
                .attr('data-resource-cpu-threads', String(data.resource_profile.cpu_threads || ''))
                .attr('data-resource-memory-budget-mb', String(data.resource_profile.memory_budget_mb || ''))
                .attr('data-resource-gpu-available', data.resource_profile.gpu_available ? 'true' : 'false')
                .attr('data-resource-gpu-vram-budget-mb', String(data.resource_profile.gpu_vram_budget_mb || ''));
        }
    };

    $('[data-settings-form]').on('submit', function (event) {
        event.preventDefault();

        const $form = $(this);
        const nativeEvent = event.originalEvent || {};
        const $saveButton = $(nativeEvent.submitter || $form.find('[data-settings-save]:visible').first());
        const formData = new FormData(this);
        const setLoading = (loading) => {
            if (typeof window.toggleLoading === 'function' && $saveButton.length) {
                window.toggleLoading($saveButton, loading);
                return;
            }

            $saveButton.prop('disabled', loading);
        };

        setLoading(true);

        $.ajax({
            url: String($form.attr('action') || ''),
            method: String($form.attr('method') || 'POST').toUpperCase(),
            data: formData,
            processData: false,
            contentType: false,
            global: false,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .done((response) => {
                refreshSettingsUi(response || {});
                notify(String(response?.message || 'Settings saved.'));
            })
            .fail((xhr) => {
                const errors = xhr?.responseJSON?.errors || {};
                const firstError = Object.values(errors).flat().find(Boolean);
                const message = firstError || xhr?.responseJSON?.message || 'Settings could not be saved.';

                notifyError(String(message));
            })
            .always(() => {
                setLoading(false);
            });
    });
};
