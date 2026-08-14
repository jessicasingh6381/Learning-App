import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MathWorkBuilder from './MathWorkBuilder.vue';
import MathVisual from './MathVisual.vue';

const config = { sections: [{ title: 'Solve', fields: [
    { id: 'answer', label: 'Least number', control: 'number' },
    { id: 'units', label: 'Units', control: 'select', choices: [{ id: 'pages', label: 'pages' }] },
    { id: 'reasoning', label: 'Explain', control: 'textarea' },
] }] };

describe('reusable Math lesson interactions', () => {
    it('introduces the checking step without assuming capacity-bounds vocabulary', () => {
        const wrapper = mount(MathVisual, { props: { config: { mode: 'routine' } } });
        expect(wrapper.text()).toContain('estimate or compare with the situation');
        expect(wrapper.text().toLowerCase()).not.toContain('bounds');
    });

    it('uses numeric and structured controls and emits durable organizer state', async () => {
        const wrapper = mount(MathWorkBuilder, { props: { modelValue: { answer: '', units: '', reasoning: '' }, config } });
        expect(wrapper.get('input').attributes('type')).toBe('number');
        await wrapper.get('input').setValue('21');
        expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual({ answer: '21', units: '', reasoning: '' });
    });

    it('shows the lower and upper capacity bounds beside a problem', () => {
        const wrapper = mount(MathVisual, { props: { config: { mode: 'capacity', total: 246, group_size: 12, lower_groups: 20, upper_groups: 21, item_unit: 'photographs', group_unit: 'pages' } } });
        expect(wrapper.text()).toContain('20 pages = 240 spaces');
        expect(wrapper.text()).toContain('21 pages = 252 spaces');
        expect(wrapper.text()).toContain('short by 6 photographs');
    });

    it('shows equal groups without revealing calculated bounds during student work', () => {
        const wrapper = mount(MathVisual, { props: { config: { mode: 'capacity', reveal: 'groups', total: 187, group_size: 48, lower_groups: 3, upper_groups: 4, item_unit: 'people', group_unit: 'buses' } } });
        expect(wrapper.findAll('.grouping span')).toHaveLength(4);
        expect(wrapper.text()).toContain('another bus?');
        expect(wrapper.text()).not.toContain('3 buses = 144 spaces');
        expect(wrapper.text()).not.toContain('4 buses = 192 spaces');
    });

    it('renders reusable concept, equal-share, and equation visuals', () => {
        const cards = mount(MathVisual, { props: { config: { mode: 'concept_cards', cards: [{ label: 'Table', detail: 'Organizes quantities.', example: 'Rows and columns' }] } } });
        expect(cards.text()).toContain('Table');
        expect(cards.text()).toContain('Rows and columns');

        const shares = mount(MathVisual, { props: { config: { mode: 'equal_share', total_label: 'All cans', total: 432, groups: 9, group_unit: 'shelves', item_unit: 'cans', per_group: 48 } } });
        expect(shares.findAll('.share-bar span')).toHaveLength(9);
        expect(shares.text()).toContain('All cans: 432');
        expect(shares.text()).toContain('48');

        const equations = mount(MathVisual, { props: { config: { mode: 'equation_steps', steps: [{ equation: '18 × 13 = 234', meaning: 'A checked relationship.' }] } } });
        expect(equations.text()).toContain('18 × 13 = 234');
        expect(equations.text()).toContain('A checked relationship.');
    });
});
