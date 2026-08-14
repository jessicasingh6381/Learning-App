import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LanguagePassportBuilder from './LanguagePassportBuilder.vue';

describe('LanguagePassportBuilder', () => {
    it('provides structured phrase choices, short writing, speaking self-check, and a digital preview', async () => {
        const model = { greetings: [], farewells: [], practice_line: '', reason: '', speaking_self_check: false };
        const wrapper = mount(LanguagePassportBuilder, { props: { modelValue: model, config: {
            writing_model: 'Hola, Kai. Hasta luego.',
            greetings: [{ id: 'hola', spanish: 'Hola', meaning: 'Hello' }, { id: 'morning', spanish: 'Buenos días', meaning: 'Good morning' }],
            farewells: [{ id: 'adios', spanish: 'Adiós', meaning: 'Goodbye' }, { id: 'later', spanish: 'Hasta luego', meaning: 'See you later' }],
        } } });

        const checks = wrapper.findAll('input[type="checkbox"]');
        await checks[0].setValue(true); await checks[1].setValue(true); await checks[2].setValue(true); await checks[3].setValue(true);
        await wrapper.find('input.form-control').setValue('Hola, Kai. Hasta luego.');
        await wrapper.find('textarea').setValue('Hasta luego fits when I will meet someone again.');
        await checks[4].setValue(true);

        expect(wrapper.get('.passport-preview').text()).toContain('Hola');
        expect(wrapper.get('.passport-preview').text()).toContain('Hasta luego');
        expect(wrapper.text()).toContain('I listened first');
    });
});
