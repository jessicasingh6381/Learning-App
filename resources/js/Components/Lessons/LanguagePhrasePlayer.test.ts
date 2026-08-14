import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import LanguagePhrasePlayer from './LanguagePhrasePlayer.vue';

describe('LanguagePhrasePlayer', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('replays a fixed Spanish phrase without requesting or recording student audio', async () => {
        class Utterance {
            text: string; lang = ''; rate = 1; onstart?: () => void; onend?: () => void; onerror?: () => void;
            constructor(text: string) { this.text = text; }
        }
        const speak = vi.fn((utterance: Utterance) => { utterance.onstart?.(); utterance.onend?.(); });
        vi.stubGlobal('SpeechSynthesisUtterance', Utterance);
        Object.defineProperty(window, 'speechSynthesis', { configurable: true, value: { speak, cancel: vi.fn() } });
        const wrapper = mount(LanguagePhrasePlayer, { props: { config: { language: 'es-MX', rate: 0.78, phrases: [{ id: 'hola', spanish: 'Hola', meaning: 'Hello', use: 'A greeting', visual: '👋', pronunciation_aid: 'OH-lah' }] } } });

        await wrapper.get('button').trigger('click');

        expect(speak).toHaveBeenCalledOnce();
        expect(speak.mock.calls[0][0].text).toBe('Hola');
        expect(speak.mock.calls[0][0].lang).toBe('es-MX');
        expect(wrapper.text()).toContain('does not record or score your voice');
    });

    it('keeps listening text hidden when an activity is audio-first', () => {
        vi.stubGlobal('SpeechSynthesisUtterance', class {});
        Object.defineProperty(window, 'speechSynthesis', { configurable: true, value: { speak: vi.fn(), cancel: vi.fn() } });
        const wrapper = mount(LanguagePhrasePlayer, { props: { config: { hide_text: true, phrases: [{ id: 'morning', spanish: 'Buenos días', label: 'morning greeting' }] } } });
        expect(wrapper.text()).not.toContain('Buenos días');
        expect(wrapper.get('button').attributes('aria-label')).toBe('Play morning greeting');
    });
});
