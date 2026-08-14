import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import LanguageWorkBuilder from './LanguageWorkBuilder.vue';

describe('LanguageWorkBuilder', () => {
    it('supports modeled Spanish choices and self-checks', async () => {
        const wrapper = mount(LanguageWorkBuilder, { props: { modelValue: { frame: '', words: [], practiced: false }, 'onUpdate:modelValue': (value: any) => wrapper.setProps({ modelValue: value }), config: { model: 'Me llamo Kai.', fields: [
            { id: 'frame', label: 'Choose a frame', control: 'select', choices: [{ id: 'me_llamo', label: 'Me llamo…' }] },
            { id: 'words', label: 'Choose words', control: 'multi_select', choices: [{ id: 'hola', label: 'hola' }] },
            { id: 'practiced', label: 'I practiced', control: 'checkbox' },
        ] } } });
        expect(wrapper.text()).toContain('Me llamo Kai.');
        await wrapper.find('input[type="radio"]').setValue();
        await wrapper.findAll('input[type="checkbox"]')[0].setValue(true);
        expect((wrapper.props('modelValue') as any).frame).toBe('me_llamo');
        expect((wrapper.props('modelValue') as any).words).toEqual(['hola']);
    });
});
