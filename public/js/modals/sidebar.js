$(function () {
    let $activeSidebar = $();
    let lastTrigger = null;
    let closeTimer = null;

    const closeSidebar = () => {
        if (!$activeSidebar.length) {
            return;
        }

        const $closingSidebar = $activeSidebar;
        const $panel = $closingSidebar.find('[data-sidebar-panel]');

        $panel.addClass('translate-x-full');
        $closingSidebar.attr('aria-hidden', 'true');
        $('[data-open-sidebar]').attr('aria-expanded', 'false');
        $activeSidebar = $();

        window.clearTimeout(closeTimer);
        closeTimer = window.setTimeout(() => {
            $closingSidebar.addClass('hidden');

            if (lastTrigger) {
                lastTrigger.focus();
            }
        }, 300);
    };

    const openSidebar = (name, trigger) => {
        const $sidebar = $(`[data-app-sidebar="${name}"]`);

        if (!$sidebar.length) {
            return;
        }

        if ($activeSidebar.length) {
            closeSidebar();
        }

        window.clearTimeout(closeTimer);
        lastTrigger = trigger;
        $activeSidebar = $sidebar;
        $sidebar.removeClass('hidden').attr('aria-hidden', 'false');
        $(trigger).attr('aria-expanded', 'true');

        window.requestAnimationFrame(() => {
            $sidebar.find('[data-sidebar-panel]').removeClass('translate-x-full');
            $sidebar.find('[data-close-sidebar]').last().trigger('focus');
        });
    };

    $(document).on('click', '[data-open-sidebar]', function () {
        openSidebar(String($(this).attr('data-open-sidebar') || ''), this);
    });

    $(document).on('click', '[data-close-sidebar]', closeSidebar);

    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && $activeSidebar.length) {
            closeSidebar();
        }
    });
});
