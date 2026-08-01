import { config, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CalendarShow from './Calendars/Show.vue';
import CalendarsIndex from './Calendars/Index.vue';
import CurriculumShow from './Curriculum/Show.vue';
import Overview from './Overview.vue';
import ProvidersIndex from './Providers/Index.vue';
import SourcesForm from './Sources/Form.vue';
import SourcesIndex from './Sources/Index.vue';
import SourcesShow from './Sources/Show.vue';

const state = vi.hoisted(() => ({
    permissions: [] as string[],
    post: vi.fn(),
    patch: vi.fn(),
    remove: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: { get: vi.fn() },
    usePage: () => ({
        props: {
            auth: { permissions: state.permissions, user: { name: 'Adult User' } },
            tenant: { name: 'Test Academy' },
            tenants: [],
            flash: {},
        },
    }),
    useForm: (values: Record<string, unknown>) => {
        const form = reactive({
            ...values,
            errors: {} as Record<string, string>,
            processing: false,
            post: state.post,
            patch: state.patch,
            delete: state.remove,
            reset: vi.fn(),
        });
        return form;
    },
}));

(globalThis as any).route = (name?: string, params?: unknown) =>
    name ? `${name}${params ? `:${JSON.stringify(params)}` : ''}` : { current: () => false };
config.global.mocks = {
    route: (globalThis as any).route,
};

const layoutStub = { template: '<main><slot /></main>' };
const academicNavStub = { template: '<nav>Academic navigation</nav>' };

describe('Academic setup UI', () => {
    beforeEach(() => {
        state.permissions = [];
        state.post.mockReset();
        state.patch.mockReset();
        state.remove.mockReset();
    });

    it('shows grouped academic navigation only to authorized adult users', () => {
        state.permissions = ['dashboard.view', 'academic-config.view'];
        const owner = mount(AuthenticatedLayout);
        expect(owner.text()).toContain('Academic setup');
        expect(owner.text()).toContain('Calendars');
        expect(owner.text()).toContain('Sources');

        state.permissions = [];
        const student = mount(AuthenticatedLayout);
        expect(student.text()).not.toContain('Academic setup');
        expect(student.text()).not.toContain('Sources');
    });

    it('renders saved overview selections, exact totals, missing steps, and copy warning', () => {
        state.permissions = ['academic-config.view', 'academic-config.manage'];
        const wrapper = mount(Overview, {
            props: {
                schoolYears: [
                    { id: 1, name: '2026-2027', status: 'active' },
                    { id: 2, name: '2027-2028', status: 'draft' },
                ],
                schoolYear: {
                    id: 1,
                    name: '2026-2027',
                    start_date: '2026-08-12',
                    end_date: '2027-05-27',
                    instructional_day_target: 180,
                },
                configuration: {
                    education_provider_id: 1,
                    calendar_profile_id: 2,
                    standards_framework_id: 3,
                    curriculum_package_id: 4,
                    status: 'draft',
                    notes: '',
                },
                summary: { base_days: 207, removed_days: 27, added_days: 0, scheduled_days: 180 },
                mappedCourseCount: 4,
                sourceCounts: { calendar: 2, curriculum: 1, courses: 3 },
                calendarSetup: { state: 'complete', source_count: 2, profile_count: 1, linked_profile_count: 0, single_source: null, can_view_sources: true, can_create_source: true, can_create_profile: false },
                checklist: {
                    school_year: true,
                    provider: true,
                    calendar: true,
                    standards: true,
                    curriculum: true,
                    courses: false,
                },
                choices: {
                    providers: [{ id: 1, name: 'CFISD', tenant_id: null }],
                    calendars: [{ id: 2, name: 'Custom Calendar', tenant_id: 1 }],
                    frameworks: [{ id: 3, name: 'TEKS', version_label: 'unversioned', tenant_id: null }],
                    packages: [{ id: 4, name: 'Grade 5 Core', version_label: '2026-2027', tenant_id: 1 }],
                },
                canManage: true,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });

        expect(wrapper.text()).toContain('Aug 12, 2026');
        expect(wrapper.text()).toContain('May 27, 2027');
        expect(wrapper.text()).toContain('207');
        expect(wrapper.text()).toContain('27');
        expect(wrapper.text()).toContain('180');
        expect(wrapper.text()).toContain('CFISD (shared)');
        expect(wrapper.text()).toContain('Custom Calendar (custom)');
        expect(wrapper.text()).toContain('courses');
        expect(wrapper.text()).toContain('Missing');
        expect(wrapper.text()).toContain('3 related sources');
        expect(wrapper.text()).toContain('A compatible Calendar Profile is selected.');
        expect(wrapper.text()).toContain('Enrollments, audits, and incompatible calendars are not copied.');
    });

    it('renders source library metadata, review state, and private attachment version', () => {
        const wrapper = mount(SourcesIndex, {
            props: {
                sources: { data: [{ id: 9, title: 'Official calendar', source_kind: 'upload', source_category: 'calendar', authority_level: 'official_provider', review_status: 'in_review', processing_status: 'not_requested', updated_at: '2026-08-01T12:00:00Z', school_year: { name: '2026-2027' }, education_provider: { name: 'CFISD' }, grade_level: null, current_file: { original_filename: 'calendar.pdf', version_number: 2 } }], links: [] },
                filters: {},
                options: { categories: ['calendar'], kinds: ['upload'], reviewStatuses: ['in_review'], schoolYears: [], providers: [], gradeLevels: [] },
                canCreate: true,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.text()).toContain('Official calendar');
        expect(wrapper.text()).toContain('In Review');
        expect(wrapper.text()).toContain('calendar.pdf (v2)');
        expect(wrapper.text()).toContain('Add source');
    });

    it('changes source intake fields by kind without offering upload replacement on edit', async () => {
        const options = { kinds: ['upload', 'url', 'manual'], categories: ['calendar'], authorityLevels: ['unknown'], providers: [], schoolYears: [], gradeLevels: [], subjects: [] };
        const wrapper = mount(SourcesForm, {
            props: { defaults: { source_kind: 'upload', source_category: 'calendar' }, options, maxUploadMegabytes: 25 },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.get('#source-file').attributes('accept')).toContain('.docx');
        expect(wrapper.text()).toContain('remain private');
        await wrapper.get('#source-kind-form').setValue('url');
        expect(wrapper.find('#source-url').exists()).toBe(true);
        expect(wrapper.text()).toContain('does not fetch');

        const edit = mount(SourcesForm, {
            props: { source: { id: 1, title: 'Existing', source_kind: 'upload', source_category: 'calendar', authority_level: 'unknown' }, options, maxUploadMegabytes: 25 },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(edit.find('#source-file').exists()).toBe(false);
        expect(edit.get('#source-kind-form').attributes('disabled')).toBeDefined();
    });

    it('shows safe source details and category-aware reviewed draft actions', () => {
        const wrapper = mount(SourcesShow, {
            props: {
                source: { id: 4, title: 'Calendar reference', description: 'Official page reference', source_kind: 'url', source_category: 'calendar', authority_level: 'official_provider', review_status: 'reviewed', processing_status: 'not_requested', source_url: 'https://example.edu/calendar', publication_date: '2026-07-01', version_label: '2026', academic_year_label: '2026-2027', notes: '', archived_at: null, education_provider_id: 1, education_provider: { name: 'Provider' }, school_year: { name: '2026-2027' }, grade_level: null, files: [] },
                links: [], linkChoices: { school_year: [] },
                courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] },
                courseDefaults: { subject_id: null, standards_framework_id: null, education_provider_id: 1, minimum_grade_level_id: null, maximum_grade_level_id: null, name: 'Calendar reference', description: 'Draft created from reviewed academic source: Calendar reference.' },
                calendarSetup: { is_calendar: true, linked_profiles: [], current_file_is_pdf: false },
                permissions: { manage: true, review: true, download: true }, reviewTransitions: ['rejected', 'archived'],
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        const external = wrapper.get('a[href="https://example.edu/calendar"]');
        expect(external.attributes('target')).toBe('_blank');
        expect(external.attributes('rel')).toContain('noopener');
        expect(wrapper.text()).toContain('Create draft Calendar Profile');
        expect(wrapper.text()).not.toContain('Create curriculum draft');
        expect(wrapper.text()).toContain('not fetched by the application');
        expect(wrapper.get('button.btn-outline-danger').text()).toContain('Archive source');
    });

    it('explains missing and source-available calendar setup states with next actions', () => {
        const base = {
            schoolYears: [{ id: 1, name: '2026-2027', status: 'active' }],
            schoolYear: { id: 1, name: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27', instructional_day_target: 180 },
            configuration: { education_provider_id: 1, calendar_profile_id: null, standards_framework_id: null, curriculum_package_id: null, status: 'draft', notes: '' },
            summary: { base_days: 207, removed_days: 0, added_days: 0, scheduled_days: 207 }, mappedCourseCount: 0,
            sourceCounts: { calendar: 0, curriculum: 0, courses: 0 },
            checklist: { school_year: true, provider: true, calendar: false, standards: false, curriculum: false, courses: false },
            choices: { providers: [], calendars: [], frameworks: [], packages: [] }, canManage: true,
        };
        const missing = mount(Overview, {
            props: { ...base, calendarSetup: { state: 'missing', source_count: 0, profile_count: 0, linked_profile_count: 0, single_source: null, can_view_sources: true, can_create_source: true, can_create_profile: false } },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(missing.text()).toContain('No Calendar Profile or related source');
        expect(missing.text()).toContain('Add source');

        const available = mount(Overview, {
            props: { ...base, sourceCounts: { calendar: 1, curriculum: 0, courses: 0 }, calendarSetup: { state: 'source_available', source_count: 1, profile_count: 0, linked_profile_count: 0, single_source: { id: 7, title: 'Calendar PDF', review_status: 'reviewed' }, can_view_sources: true, can_create_source: true, can_create_profile: true } },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(available.text()).toContain('Source available');
        expect(available.text()).toContain('1 related source');
        expect(available.text()).toContain('View source');
        expect(available.text()).toContain('Create calendar profile');
        expect(available.text()).toContain('Missing');

        const multiple = mount(Overview, {
            props: { ...base, sourceCounts: { calendar: 2, curriculum: 0, courses: 0 }, calendarSetup: { state: 'source_available', source_count: 2, profile_count: 0, linked_profile_count: 0, single_source: null, can_view_sources: true, can_create_source: true, can_create_profile: false } },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        const viewSources = multiple.findAll('a').find((link) => link.text() === 'View sources');
        expect(viewSources?.attributes('href')).toContain('category');
        expect(viewSources?.attributes('href')).toContain('school_year_id');
    });

    it('routes multiple calendar sources to a filtered library summary', () => {
        const wrapper = mount(SourcesIndex, {
            props: { sources: { data: [], links: [] }, filters: { category: 'calendar', school_year_id: 1 }, filterSummary: 'Calendar sources for 2026-2027', options: { categories: ['calendar'], kinds: [], reviewStatuses: [], schoolYears: [{ id: 1, name: '2026-2027' }], providers: [], gradeLevels: [] }, canCreate: true },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.get('h1').text()).toBe('Calendar sources for 2026-2027');
        expect(wrapper.text()).toContain('Clear');
    });

    it('shows inline PDF only for PDFs and explains review before calendar draft creation', () => {
        const common = {
            links: [], linkChoices: { school_year: [] }, courseChoices: { subjects: [], gradeLevels: [], providers: [], frameworks: [] },
            courseDefaults: {}, permissions: { manage: true, review: true, download: true }, reviewTransitions: ['in_review', 'rejected', 'archived'],
            calendarSetup: { is_calendar: true, linked_profiles: [], current_file_is_pdf: true },
        };
        const pdf = mount(SourcesShow, {
            props: { ...common, source: { id: 1, title: 'Calendar PDF', description: '', source_kind: 'upload', source_category: 'calendar', authority_level: 'official_provider', review_status: 'unreviewed', processing_status: 'not_requested', publication_date: null, version_label: null, academic_year_label: '2026-2027', notes: '', archived_at: null, school_year_id: 1, education_provider: null, school_year: { name: '2026-2027' }, grade_level: null, files: [{ id: 3, version_number: 1, is_current: true, original_filename: 'calendar.pdf', mime_type: 'application/pdf', extension: 'pdf', file_size: 100, checksum_sha256: 'a'.repeat(64) }] } },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(pdf.text()).toContain('Review this source before creating a Calendar Profile.');
        expect(pdf.text()).toContain('View PDF');
        expect(pdf.text()).toContain('Download');
        expect(pdf.text()).toContain('Start review');
        expect(pdf.text()).not.toContain('Create draft Calendar Profile');

        const txt = mount(SourcesShow, {
            props: { ...common, calendarSetup: { ...common.calendarSetup, current_file_is_pdf: false }, source: { ...pdf.props('source'), files: [{ ...pdf.props('source').files[0], original_filename: 'notes.txt', mime_type: 'text/plain', extension: 'txt' }] } },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(txt.text()).not.toContain('View PDF');
        expect(txt.text()).toContain('Download');
    });

    it('shows draft Calendar Profiles and their linked source in the Calendars tab', () => {
        state.permissions = ['calendars.view', 'calendars.manage'];
        const wrapper = mount(CalendarsIndex, {
            props: { calendars: [{ id: 4, name: 'Calendar PDF', academic_year_label: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27', status: 'draft', events_count: 0, is_shared: false, education_provider: { name: 'CFISD' }, linked_sources: [{ id: 9, title: 'Calendar PDF source' }] }] },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub, OwnershipBadge: true } },
        });
        expect(wrapper.text()).toContain('Calendar PDF');
        expect(wrapper.text()).toContain('draft');
        expect(wrapper.text()).toContain('1 linked source');
        expect(wrapper.text()).toContain('Calendar PDF source');
        expect(wrapper.text()).toContain('0');
    });

    it('distinguishes shared providers and hides platform edit actions', () => {
        state.permissions = ['providers.view', 'providers.manage'];
        const wrapper = mount(ProvidersIndex, {
            props: {
                providers: [
                    { id: 1, name: 'CFISD', provider_type: 'district', is_shared: true, status: 'active' },
                    { id: 2, name: 'Custom', provider_type: 'custom', is_shared: false, status: 'active' },
                ],
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.text()).toContain('Platform shared');
        expect(wrapper.text()).toContain('Tenant custom');
        expect(wrapper.findAll('a').filter((link) => link.text() === 'Edit')).toHaveLength(1);
    });

    it('renders single and multi-day events, effect choices, totals, and accessible validation fields', () => {
        state.permissions = ['calendars.view', 'calendars.manage'];
        const wrapper = mount(CalendarShow, {
            props: {
                calendar: {
                    id: 1, name: 'Custom Calendar', start_date: '2026-08-12', end_date: '2027-05-27',
                    timezone: 'America/Chicago', is_shared: false,
                    events: [
                        { id: 1, name: 'Holiday', event_date: '2026-09-07', end_date: null, event_type: 'holiday', instructional_effect: 'non_instructional', status: 'active' },
                        { id: 2, name: 'Fall break', event_date: '2026-11-23', end_date: '2026-11-27', event_type: 'break', instructional_effect: 'non_instructional', status: 'active' },
                    ],
                },
                summaries: [{ school_year_id: 1, school_year_name: '2026-2027', compatible: true, base_days: 207, removed_days: 6, added_days: 1, scheduled_days: 202 }],
                linkedSources: [{ id: 9, title: 'Calendar PDF source' }],
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.text()).toContain('Sep 7, 2026');
        expect(wrapper.text()).toContain('Nov 23, 2026 – Nov 27, 2026');
        expect(wrapper.text()).toContain('Instructional override');
        expect(wrapper.text()).toContain('207');
        expect(wrapper.text()).toContain('202');
        expect(wrapper.text()).toContain('Use in Academic Setup for 2026-2027');
        expect(wrapper.text()).toContain('View source: Calendar PDF source');
        expect(wrapper.get('#event-name').attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.get('#event-end').attributes('type')).toBe('date');
    });

    it('supports empty package state and disables mapping changes outside drafts', () => {
        state.permissions = ['curriculum.view', 'curriculum.manage'];
        const wrapper = mount(CurriculumShow, {
            props: {
                package: {
                    id: 1, name: 'Grade 5 Core', version_label: '2026-2027',
                    status: 'active', is_shared: false, course_mappings: [],
                },
                courses: [],
                gradeLevels: [],
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.text()).toContain('This version is protected.');
        expect(wrapper.text()).toContain('No courses mapped.');
        expect(wrapper.find('#mapping-course').exists()).toBe(false);
    });
});
