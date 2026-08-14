import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ElarReadingPassage from './ElarReadingPassage.vue';
import ElarResponseBuilder from './ElarResponseBuilder.vue';

const passage = { title: 'A Test Passage', source_label: 'Application-created instructional text', source_note: 'Not curriculum-supplied.', paragraphs: [{ number: 1, sentences: [{ id: 'one', text: 'The first sentence.' }, { id: 'two', text: 'The evidence sentence.' }] }] };

describe('ELAR reading interactions', () => {
    it('keeps a numbered, sourced passage visible and highlights modeled evidence', () => {
        const wrapper = mount(ElarReadingPassage, { props: { passage, focusSentenceIds: ['two'] } });
        expect(wrapper.text()).toContain('Application-created instructional text');
        expect(wrapper.text()).toContain('Not curriculum-supplied.');
        expect(wrapper.find('.focused').text()).toBe('The evidence sentence.');
    });

    it('shows the passage beside selectable evidence and writing fields', async () => {
        const model = { evidence: '', explanation: '' };
        const wrapper = mount(ElarResponseBuilder, { props: { modelValue: model, passage, config: { fields: [{ id: 'evidence', label: 'Choose evidence', control: 'evidence_select', choices: [{ id: 'two', paragraph: 1, text: 'The evidence sentence.' }] }, { id: 'explanation', label: 'Explain', control: 'textarea' }] } } });
        expect(wrapper.text()).toContain('The first sentence.');
        expect(wrapper.text()).toContain('My reading evidence');
        await wrapper.find('input').setValue();
        expect(model.evidence).toBe('two');
    });
});
