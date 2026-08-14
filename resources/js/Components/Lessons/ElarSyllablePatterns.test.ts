import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ElarSyllablePatterns from './ElarSyllablePatterns.vue';

describe('ElarSyllablePatterns', () => {
    it('shows a visual breakdown and vowel clue for every pattern', () => {
        const wrapper = mount(ElarSyllablePatterns, { props: { patterns: [
            { id: 'closed', label: 'Closed', example: 'rob', breakdown: 'r–o–b', detail: 'A consonant closes the syllable.', vowel_clue: 'Short o.' },
            { id: 'vce', label: 'Final VCe', example: 'make', breakdown: 'm–a–k–e', detail: 'Vowel–consonant–silent e.', vowel_clue: 'Long a.' },
        ] } });
        expect(wrapper.findAll('article')).toHaveLength(2);
        expect(wrapper.text()).toContain('m–a–k–e');
        expect(wrapper.text()).toContain('Vowel–consonant–silent e.');
        expect(wrapper.text()).toContain('Long a.');
    });
});
