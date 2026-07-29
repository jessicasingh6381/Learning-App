import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import StatusBadge from './StatusBadge.vue';

describe('StatusBadge', () => {
    it('renders a human-readable status with an accessible label and semantic color', () => {
        const wrapper = mount(StatusBadge, {
            props: { status: 'in_progress' },
        });

        expect(wrapper.text()).toBe('in progress');
        expect(wrapper.attributes('aria-label')).toBe('Status: in progress');
        expect(wrapper.classes()).toContain('text-bg-primary');
    });

    it('uses the success color for completed records', () => {
        const wrapper = mount(StatusBadge, {
            props: { status: 'completed' },
        });

        expect(wrapper.classes()).toContain('text-bg-success');
    });
});
