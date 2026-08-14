import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TechnologyCodeWorkspace from './TechnologyCodeWorkspace.vue';

describe('TechnologyCodeWorkspace', () => {
    it('previews only simple quoted print statements and labels the result as non-execution', async () => {
        const wrapper = mount(TechnologyCodeWorkspace, {
            props: {
                modelValue: { source: 'print("Mission online")\nprint("Destination: Mars")', prediction: '', reflection: '' },
                'onUpdate:modelValue': (value: any) => wrapper.setProps({ modelValue: value }),
                config: { starter_code: 'print("Starter")', prediction_label: 'Predict', reflection_label: 'Explain' },
            },
        });
        await wrapper.get('.preview-button').trigger('click');
        expect(wrapper.get('.output').text()).toContain('Mission online');
        expect(wrapper.get('.output').text()).toContain('Destination: Mars');
        expect(wrapper.get('.runtime-notice').text()).toContain('not Python execution');
        expect(wrapper.text()).toContain('never sends code to a Python process');
    });

    it('flags unsupported lines instead of pretending to run them', async () => {
        const wrapper = mount(TechnologyCodeWorkspace, {
            props: {
                modelValue: { source: 'import os\nprint("Safe line")', prediction: '', reflection: '' },
                config: { starter_code: 'print("Starter")', prediction_label: 'Predict', reflection_label: 'Explain' },
            },
        });
        await wrapper.get('.preview-button').trigger('click');
        expect(wrapper.get('.warning').text()).toContain('Unsupported line: 1');
        expect(wrapper.find('pre').exists()).toBe(false);
    });

    it('previews lesson-approved variables, updates, numbers, and simulated input deterministically', async () => {
        const wrapper = mount(TechnologyCodeWorkspace, {
            props: {
                modelValue: {
                    source: 'commander_name = input("Commander: ")\nfuel = 100\nprint("Commander:", commander_name)\nprint("Fuel:", fuel)\nfuel = 95\nprint("Updated fuel:", fuel)',
                    prediction: 'Kai and both fuel values will appear.',
                    reflection: 'The later assignment changes only the stored fuel value.',
                    inputs: { commander_name: 'Kai' },
                },
                config: {
                    starter_code: 'print("Starter")', prediction_label: 'Predict', reflection_label: 'Explain',
                    input_fields: [{ id: 'commander_name', label: 'Commander test response' }],
                },
            },
        });

        await wrapper.get('.preview-button').trigger('click');

        expect(wrapper.get('.output').text()).toContain('Commander: Kai');
        expect(wrapper.get('.output').text()).toContain('Fuel: 100');
        expect(wrapper.get('.output').text()).toContain('Updated fuel: 95');
        expect(wrapper.find('.warning').exists()).toBe(false);
        expect(wrapper.get('.simulated-inputs').text()).toContain('Test responses');
    });
});
