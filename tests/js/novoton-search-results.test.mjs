import { beforeAll, describe, expect, it } from 'vitest';

/**
 * Behavioral tests for the novoton search-results module
 * (addon-novoton-holidays/js/addons/novoton_holidays/search-results.js) —
 * the "More info" room-details modal extracted from search.tpl.
 */
beforeAll(async () => {
    document.body.innerHTML = `
        <div id="modal-content-r42" style="display:none;"><p>Room details 42</p></div>
        <div id="info-modal" style="display:none;">
            <div id="info-modal-content"></div>
        </div>`;
    await import('../../addon-novoton-holidays/js/addons/novoton_holidays/search-results.js');
});

describe('openInfoModal / closeInfoModal', () => {
    it('copies the row content into the modal and shows it', () => {
        window.openInfoModal('r42');
        expect(document.getElementById('info-modal-content').innerHTML).toContain('Room details 42');
        expect(document.getElementById('info-modal').style.display).toBe('flex');
        expect(document.body.style.overflow).toBe('hidden');
    });

    it('closes and restores scrolling', () => {
        window.closeInfoModal();
        expect(document.getElementById('info-modal').style.display).toBe('none');
        expect(document.body.style.overflow).toBe('');
    });

    it('ignores unknown row ids without touching the modal', () => {
        window.closeInfoModal();
        window.openInfoModal('missing-row');
        expect(document.getElementById('info-modal').style.display).toBe('none');
    });

    it('Escape closes the modal (document-level handler)', () => {
        window.openInfoModal('r42');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(document.getElementById('info-modal').style.display).toBe('none');
    });
});
