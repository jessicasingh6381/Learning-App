import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Form from './Form.vue';

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { template: '<span />' },
        useForm: (values: Record<string, unknown>) =>
            reactive({
                ...values,
                errors: {},
                processing: false,
                post: vi.fn(),
            }),
    };
});

const schoolYears = [
    {
        id: 1,
        name: '2026-2027',
        start_date: '2026-08-12',
        end_date: '2027-05-27',
    },
    {
        id: 2,
        name: '2027-2028',
        start_date: '2027-08-11',
        end_date: '2028-05-26',
    },
];

const mountForm = (overrides: Record<string, unknown> = {}) =>
    mount(Form, {
        props: {
            students: [
                {
                    id: 1,
                    first_name: 'Kai',
                    last_name: 'Learner',
                    preferred_name: null,
                },
            ],
            schoolYears,
            gradeLevels: [
                { id: 6, name: 'Grade 5' },
                { id: 7, name: 'Grade 6' },
            ],
            oldInput: null,
            ...overrides,
        },
        global: {
            stubs: {
                AuthenticatedLayout: {
                    template: '<main><slot /></main>',
                },
            },
        },
    });

describe('Enrollment form date defaults', () => {
    it('defaults a preselected school year to its exact date-only start value', () => {
        const dateConstructor = vi
            .spyOn(globalThis, 'Date')
            .mockImplementation(() => {
                throw new Error('Enrollment defaults must not construct Date');
            });

        const wrapper = mountForm();

        expect(
            wrapper.get<HTMLInputElement>('#enrollment_date').element.value,
        ).toBe('2026-08-12');
        expect(wrapper.text()).toContain(
            'Defaults to the first day of the selected school year. Change it if the student starts later.',
        );

        dateConstructor.mockRestore();
    });

    it('leaves the date blank when no school year is available', () => {
        const wrapper = mountForm({ schoolYears: [] });

        expect(wrapper.find('#enrollment_date').exists()).toBe(false);
        expect(wrapper.text()).toContain(
            'Add an active student and a draft or active school year first.',
        );
    });

    it('leaves the date blank until an unselected school year is selected', async () => {
        const wrapper = mountForm({
            oldInput: {
                student_id: 1,
                school_year_id: null,
                grade_level_id: 6,
                enrollment_date: null,
                completion_date: null,
                status: 'active',
            },
        });
        const date = wrapper.get<HTMLInputElement>('#enrollment_date');

        expect(wrapper.get<HTMLSelectElement>('#school_year_id').element.value).toBe(
            '',
        );
        expect(date.element.value).toBe('');

        await wrapper.get('#school_year_id').setValue('1');
        expect(date.element.value).toBe('2026-08-12');
    });

    it('updates only an untouched automatic default when the school year changes', async () => {
        const wrapper = mountForm();
        const date = wrapper.get<HTMLInputElement>('#enrollment_date');

        await wrapper.get('#school_year_id').setValue('2');
        expect(date.element.value).toBe('2027-08-11');

        await date.setValue('2027-09-15');
        await wrapper.get('#grade_level_id').setValue('7');
        await wrapper.get('#enrollment_status').setValue('planned');

        expect(date.element.value).toBe('2027-09-15');

        await wrapper.get('#school_year_id').setValue('1');
        expect(date.element.value).toBe('2027-09-15');
    });

    it('preserves old submitted input instead of applying the school-year default', async () => {
        const wrapper = mountForm({
            oldInput: {
                student_id: 1,
                school_year_id: 1,
                grade_level_id: 6,
                enrollment_date: '2026-09-15',
                completion_date: null,
                status: 'active',
            },
        });
        const date = wrapper.get<HTMLInputElement>('#enrollment_date');

        expect(date.element.value).toBe('2026-09-15');

        await wrapper.get('#school_year_id').setValue('2');
        expect(date.element.value).toBe('2026-09-15');
    });
});
