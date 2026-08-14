import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ElarResponseBuilder from './ElarResponseBuilder.vue';

describe('ElarResponseBuilder', () => {
    it('keeps selected passage evidence visible beside written reasoning', async () => {
        const wrapper = mount(ElarResponseBuilder, {
            props: {
                modelValue: { evidence: '', reasoning: '' },
                'onUpdate:modelValue': (value: any) => wrapper.setProps({ modelValue: value }),
                config: { fields: [
                    { id: 'evidence', label: 'Choose evidence', control: 'evidence_select', choices: [{ id: 'm3s2', paragraph: 3, text: 'Mara records the problem and studies the hinge.' }] },
                    { id: 'reasoning', label: 'Explain the connection', control: 'textarea' },
                ] },
            },
        });

        await wrapper.get('input[value="m3s2"]').setValue(true);

        expect(wrapper.get('.evidence-tray').text()).toContain('Evidence saved with this work');
        expect(wrapper.get('.evidence-tray').text()).toContain('Mara records the problem');
        expect(wrapper.find('textarea').exists()).toBe(true);
    });
});
