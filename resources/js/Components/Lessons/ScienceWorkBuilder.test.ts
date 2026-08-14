import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ScienceWorkBuilder from './ScienceWorkBuilder.vue';

const config = {
    kind: 'water_cycle_model',
    sections: [{ title: 'Label and explain', fields: [
        { id: 'upward_arrow', label: 'Upward water', control: 'select', choices: [{ id: 'evaporation', label: 'Evaporation' }] },
        { id: 'explanation', label: 'Explain the cycle', control: 'textarea' },
    ] }],
};

describe('ScienceWorkBuilder', () => {
    it('renders structured fields and emits a complete structured state update', async () => {
        const wrapper = mount(ScienceWorkBuilder, { props: { modelValue: { upward_arrow: '', explanation: '' }, config } });
        await wrapper.get('select').setValue('evaporation');
        expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual({ upward_arrow: 'evaporation', explanation: '' });
        expect(wrapper.get('.cycle-preview').attributes('aria-label')).toBe('Live labeled water-cycle model');
    });

    it('locks controls after structured work is complete', () => {
        const wrapper = mount(ScienceWorkBuilder, { props: { modelValue: { upward_arrow: 'evaporation', explanation: 'Water moves.' }, config, disabled: true } });
        expect(wrapper.get('select').attributes('disabled')).toBeDefined();
        expect(wrapper.get('textarea').attributes('disabled')).toBeDefined();
    });

    it('provides an in-app timer for timed observation work', () => {
        const wrapper = mount(ScienceWorkBuilder, { props: { modelValue: {}, config: { kind: 'evaporation_observation', sections: [] } } });
        expect(wrapper.get('[aria-label="Observation timer"]').text()).toContain('Start timer');
        expect(wrapper.text()).toContain('00:00');
    });
});
