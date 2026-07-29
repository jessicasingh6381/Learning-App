import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import InstructionalScheduleFields from './InstructionalScheduleFields.vue';

describe('InstructionalScheduleFields', () => {
    it('selects Monday through Friday for the five-day preset', async () => {
        const wrapper = mount(InstructionalScheduleFields, {
            props: {
                scheduleType: 'custom',
                weekdays: [2, 3, 4, 5],
            },
        });

        await wrapper.get('select').setValue('five_day');

        expect(wrapper.emitted('update:scheduleType')?.at(-1)).toEqual([
            'five_day',
        ]);
        expect(wrapper.emitted('update:weekdays')?.at(-1)).toEqual([
            [1, 2, 3, 4, 5],
        ]);
    });

    it('selects Monday through Thursday for the four-day preset', async () => {
        const wrapper = mount(InstructionalScheduleFields, {
            props: {
                scheduleType: 'five_day',
                weekdays: [1, 2, 3, 4, 5],
            },
        });

        await wrapper.get('select').setValue('four_day');

        expect(wrapper.emitted('update:scheduleType')?.at(-1)).toEqual([
            'four_day',
        ]);
        expect(wrapper.emitted('update:weekdays')?.at(-1)).toEqual([
            [1, 2, 3, 4],
        ]);
    });

    it('keeps weekdays editable for custom schedules', async () => {
        const wrapper = mount(InstructionalScheduleFields, {
            props: {
                scheduleType: 'custom',
                weekdays: [2, 3, 4],
            },
        });

        await wrapper
            .get('#instructional_weekday_5')
            .setValue(true);

        expect(wrapper.emitted('update:weekdays')?.at(-1)).toEqual([
            [2, 3, 4, 5],
        ]);
        expect(wrapper.emitted('update:scheduleType')).toBeUndefined();
    });

    it('switches a preset to custom when a weekday is changed manually', async () => {
        const wrapper = mount(InstructionalScheduleFields, {
            props: {
                scheduleType: 'five_day',
                weekdays: [1, 2, 3, 4, 5],
            },
        });

        await wrapper
            .get('#instructional_weekday_1')
            .setValue(false);

        expect(wrapper.emitted('update:weekdays')?.at(-1)).toEqual([
            [2, 3, 4, 5],
        ]);
        expect(wrapper.emitted('update:scheduleType')?.at(-1)).toEqual([
            'custom',
        ]);
    });
});
