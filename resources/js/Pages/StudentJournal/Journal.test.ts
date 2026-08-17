import { mount } from '@vue/test-utils';
import axios from 'axios';
import { reactive } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Show from './Show.vue';

vi.mock('axios');
const routeMock = Object.assign((name?: string) => name ? `/${name}` : ({ current: () => false }), { current: () => false });
vi.stubGlobal('route', routeMock);

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { tenant: { name: 'Cosmic Quest Academy' }, flash: {} } }),
    useForm: (values: Record<string, unknown>) => reactive({ ...values, errors: {}, processing: false, post }),
}));

const entry = (status = 'assigned') => ({
    id: 1,
    date: '2026-08-17',
    title: 'The Talking Dog',
    prompt: 'Your dog suddenly learns how to talk.',
    include_hints: ['The first words', 'What happens next'],
    category: 'Funny Fiction',
    response: '',
    status,
    word_count: 0,
    last_saved_at: null,
    submitted_at: status === 'submitted' ? '2026-08-17T09:00:00Z' : null,
    teacher_feedback: null,
    story_check: [{ key: 'ending', label: 'Ending', passed: false, message: 'Keep going.' }],
});

describe('Creative-writing journal editor', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('shows the full goal, autosaves after a debounce, and never blocks submission', async () => {
        vi.useFakeTimers();
        vi.mocked(axios.patch).mockResolvedValue({ data: { story_check: [] } });
        const wrapper = mount(Show, { props: { entry: entry(), history_url: '/history', autosave_url: '/draft', submit_url: '/submit' }, global: { mocks: { route: routeMock } } });

        expect(wrapper.text()).toContain('Today’s Writing Mission');
        expect(wrapper.text()).toContain('A clear beginning, middle, and ending');
        expect(wrapper.text()).toContain('A complete shorter story is allowed');
        expect(wrapper.text()).toContain('These suggestions never block submission');

        await wrapper.get('textarea').setValue('My dog said hello to Mission Control.');
        expect(wrapper.text()).toContain('7 words');
        await vi.advanceTimersByTimeAsync(650);
        expect(axios.patch).toHaveBeenCalledWith('/draft', { response: 'My dog said hello to Mission Control.' });

        await wrapper.get('button.btn-warning').trigger('click');
        expect(post).toHaveBeenCalledWith('/submit', { preserveScroll: true });
    });

    it('renders submitted writing read-only without another submit action', () => {
        const wrapper = mount(Show, { props: { entry: { ...entry('submitted'), response: 'Finished story.' }, history_url: '/history', autosave_url: '/draft', submit_url: '/submit' }, global: { mocks: { route: routeMock } } });

        expect(wrapper.get('textarea').attributes()).toHaveProperty('readonly');
        expect(wrapper.text()).toContain('Your story is preserved');
        expect(wrapper.find('button.btn-warning').exists()).toBe(false);
    });
});
