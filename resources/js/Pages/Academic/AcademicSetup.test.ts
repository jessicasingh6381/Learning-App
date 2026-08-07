import { config, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CalendarLifecycleActions from '@/Components/CalendarLifecycleActions.vue';
import CalendarShow from './Calendars/Show.vue';
import CalendarImportShow from './CalendarImports/Show.vue';
import CalendarsIndex from './Calendars/Index.vue';
import CalendarForm from './Calendars/Form.vue';
import CurriculumShow from './Curriculum/Show.vue';
import Overview from './Overview.vue';
import ProvidersIndex from './Providers/Index.vue';
import SourcesForm from './Sources/Form.vue';
import SourcesIndex from './Sources/Index.vue';
import SourcesShow from './Sources/Show.vue';

const state = vi.hoisted(() => ({
    permissions: [] as string[],
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    remove: vi.fn(),
    routerOn: vi.fn((_event?: string, _callback?: (event: { preventDefault: () => void }) => void) => vi.fn()),
    routerDelete: vi.fn(),
    processing: false,
    errors: {} as Record<string, string>,
    forms: [] as Array<Record<string, any>>,
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    router: { get: vi.fn(), on: state.routerOn, delete: state.routerDelete },
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
            errors: state.errors,
            processing: state.processing,
            post: state.post,
            put: state.put,
            patch: state.patch,
            delete: state.remove,
            reset: vi.fn(),
            clearErrors: vi.fn(),
        });
        state.forms.push(form);
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
const lifecycle = (overrides: Record<string, unknown> = {}) => ({
    academic_configuration_count: 0, active_configuration_count: 0, event_count: 0, linked_source_count: 0,
    is_in_use: false, usage: [], can_manage: true, can_edit: true, can_archive: true, can_restore: false,
    can_delete: true, archive_blockers: [], deletion_blockers: [], ...overrides,
});

describe('Academic setup UI', () => {
    beforeEach(() => {
        state.permissions = [];
        state.post.mockReset();
        state.put.mockReset();
        state.patch.mockReset();
        state.remove.mockReset();
        state.routerOn.mockClear();
        state.routerDelete.mockReset();
        state.processing = false;
        state.errors = {};
        state.forms = [];
    });

    it('keeps advanced academic setup in Settings and hides it from unauthorized adults', () => {
        state.permissions = ['workspace.view', 'advanced-academic.view', 'academic-config.view'];
        const owner = mount(AuthenticatedLayout);
        expect(owner.text()).toContain('Advanced Academic Setup');
        expect(owner.text()).toContain('Learning Plan');
        expect(owner.text()).toContain('Calendar');

        state.permissions = ['workspace.view', 'academic-config.view'];
        const parent = mount(AuthenticatedLayout);
        expect(parent.text()).not.toContain('Advanced Academic Setup');
        expect(parent.text()).toContain('Learning Plan');
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

        const provenance = mount(Overview, {
            props: {
                ...base,
                configuration: { ...base.configuration, calendar_profile_id: 4 },
                sourceCounts: { calendar: 1, curriculum: 0, courses: 0 },
                checklist: { ...base.checklist, calendar: true },
                calendarSetup: { state: 'complete', source_count: 1, profile_count: 1, linked_profile_count: 0, selected_profile_id: 4, selected_profile_has_source_website: true, unlinked_source_count: 1, single_source: { id: 7 }, can_view_sources: true, can_create_source: true, can_create_profile: false },
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(provenance.text()).toContain('Profile available with a source website. One related source document is not linked.');
        expect(provenance.text()).toContain('View profile');
        expect(provenance.text()).toContain('Link source');
        expect(provenance.text()).toContain('Complete');
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
            props: { filters: { show: 'active' }, calendars: [{ id: 4, name: 'Calendar PDF', academic_year_label: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-27', status: 'draft', events_count: 0, is_shared: false, has_source_website: true, education_provider: { name: 'CFISD' }, linked_sources: [{ id: 9, title: 'Calendar PDF source' }], lifecycle: lifecycle({ linked_source_count: 1, can_delete: false, deletion_blockers: ['Has 1 linked source document.'] }) }] },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub, OwnershipBadge: true } },
        });
        expect(wrapper.text()).toContain('Calendar PDF');
        expect(wrapper.text()).toContain('draft');
        expect(wrapper.text()).toContain('Source website · 1 linked document');
        expect(wrapper.text()).not.toContain('https://');
        expect(wrapper.text()).toContain('0');
    });

    it('renders archived filtering, archived status, restoration, and in-use context', () => {
        state.permissions = ['calendars.view', 'calendars.manage'];
        const wrapper = mount(CalendarsIndex, {
            props: {
                filters: { show: 'archived' },
                calendars: [{
                    id: 1, name: 'Archived district calendar', academic_year_label: '2026-2027',
                    start_date: '2026-08-12', end_date: '2027-05-27', status: 'archived', is_shared: false,
                    has_source_website: true, education_provider: { name: 'CFISD' }, linked_sources: [],
                    lifecycle: lifecycle({ can_edit: false, can_archive: false, can_restore: true, can_delete: false, is_in_use: true, academic_configuration_count: 1, active_configuration_count: 1, usage: [{ school_year: '2026-2027', status: 'draft' }], deletion_blockers: ['Selected by 1 current Academic Setup configuration.'] }),
                }],
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub, OwnershipBadge: true } },
        });
        expect(wrapper.get('a[aria-current="page"]').text()).toBe('Archived');
        expect(wrapper.text()).toContain('Archived');
        expect(wrapper.text()).toContain('In use');
        expect(wrapper.text()).toContain('Used by 2026-2027 Academic Setup (draft)');
        expect(wrapper.text()).toContain('Restore');
        expect(wrapper.text()).toContain('Permanent deletion unavailable');
        expect(wrapper.text()).not.toContain('Edit');
    });

    it('requires accessible confirmations for archive and irreversible deletion', async () => {
        const archive = mount(CalendarLifecycleActions, { props: { calendar: { id: 1, name: 'Calendar', status: 'draft' }, lifecycle: lifecycle() } });
        await archive.get('button.btn-outline-secondary').trigger('click');
        const archiveDialog = archive.get('[role="dialog"]');
        expect(archiveDialog.attributes('aria-modal')).toBe('true');
        expect(archiveDialog.text()).toContain('Archive this Calendar Profile?');
        expect(archiveDialog.text()).toContain('Calendar Events, source documents, and Academic Setup history will not be deleted.');
        await archiveDialog.get('button.btn-warning').trigger('click');
        expect(state.patch).toHaveBeenCalledWith(expect.stringContaining('academic.calendars.archive'), expect.any(Object));

        const removal = mount(CalendarLifecycleActions, { props: { calendar: { id: 2, name: 'Unused Calendar', status: 'draft' }, lifecycle: lifecycle() } });
        await removal.get('button.btn-outline-danger').trigger('click');
        const deleteDialog = removal.get('[role="dialog"]');
        const submit = deleteDialog.get('button[type="submit"]');
        expect(deleteDialog.text()).toContain('This cannot be undone.');
        expect(submit.attributes('disabled')).toBeDefined();
        await deleteDialog.get('#calendar-delete-confirmation').setValue('DELETE');
        expect(deleteDialog.get('button[type="submit"]').attributes('disabled')).toBeUndefined();
        await deleteDialog.get('form').trigger('submit');
        expect(state.remove).toHaveBeenCalledWith(expect.stringContaining('academic.calendars.destroy'), expect.any(Object));
    });

    it('shows dependency and authorization feedback without offering doomed lifecycle actions', () => {
        const blocked = mount(CalendarLifecycleActions, {
            props: {
                calendar: { id: 1, name: 'Used Calendar', status: 'draft' },
                lifecycle: lifecycle({ can_archive: false, can_delete: false, archive_blockers: ['Selected in current Academic Setup.'], deletion_blockers: ['Contains 4 Calendar Events.', 'Has 1 linked source document.'] }),
            },
        });
        expect(blocked.text()).toContain('Archive unavailable');
        expect(blocked.text()).toContain('Selected in current Academic Setup.');
        expect(blocked.text()).toContain('Contains 4 Calendar Events.');
        expect(blocked.text()).toContain('Has 1 linked source document.');
        expect(blocked.find('button.btn-outline-danger').exists()).toBe(false);

        const student = mount(CalendarLifecycleActions, { props: { calendar: { id: 1, name: 'Calendar', status: 'draft' }, lifecycle: lifecycle({ can_manage: false, can_edit: false, can_archive: false, can_delete: false }) } });
        expect(student.find('button').exists()).toBe(false);
        expect(student.text()).toBe('');

        state.errors = { lifecycle: 'The Calendar Profile changed before the action completed.' };
        const errored = mount(CalendarLifecycleActions, { props: { calendar: { id: 1, name: 'Calendar', status: 'draft' }, lifecycle: lifecycle() } });
        expect(errored.get('[role="alert"]').text()).toContain('changed before the action completed');
    });

    it('disables lifecycle controls while a request is processing', () => {
        state.processing = true;
        const wrapper = mount(CalendarLifecycleActions, { props: { calendar: { id: 1, name: 'Calendar', status: 'draft' }, lifecycle: lifecycle() } });
        expect(wrapper.get('button.btn-outline-secondary').attributes('disabled')).toBeDefined();
        expect(wrapper.get('button.btn-outline-danger').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Archiving…');
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
                    timezone: 'America/Chicago', is_shared: false, academic_year_label: '2026-2027', status: 'draft', education_provider: null,
                    events: [
                        { id: 1, name: 'Holiday', event_date: '2026-09-07', end_date: null, event_type: 'holiday', instructional_effect: 'non_instructional', status: 'active' },
                        { id: 2, name: 'Fall break', event_date: '2026-11-23', end_date: '2026-11-27', event_type: 'break', instructional_effect: 'non_instructional', status: 'active' },
                    ],
                },
                summaries: [{ school_year_id: 1, school_year_name: '2026-2027', compatible: true, base_days: 207, removed_days: 6, added_days: 1, scheduled_days: 202 }],
                linkedSources: [{ id: 9, link_id: 3, title: 'Calendar PDF source', source_kind: 'upload', source_category: 'calendar', authority_level: 'official_provider', review_status: 'reviewed', current_file: null, external_url: null, can_manage: true, can_download: true }],
                suggestedSources: [],
                sourceWebsite: null,
                lifecycle: lifecycle({ event_count: 2, linked_source_count: 1 }),
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.text()).toContain('Sep 7, 2026');
        expect(wrapper.text()).toContain('Nov 23, 2026 – Nov 27, 2026');
        expect(wrapper.text()).toContain('Instructional override');
        expect(wrapper.text()).toContain('207');
        expect(wrapper.text()).toContain('202');
        expect(wrapper.text()).toContain('Use in Academic Setup');
        expect(wrapper.text()).toContain('Calendar PDF source');
        expect(wrapper.get('#event-name').attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.get('#event-end').attributes('type')).toBe('date');
    });

    it('renders direct source metadata and a linked PDF as separate provenance', () => {
        state.permissions = ['calendars.view', 'calendars.manage'];
        const wrapper = mount(CalendarShow, {
            props: {
                calendar: { id: 1, name: 'CFISD Calendar', academic_year_label: '2026-2027', start_date: '2026-08-12', end_date: '2027-05-29', timezone: 'America/Chicago', status: 'active', source_version: 'Revision 1', education_provider: { id: 1, name: 'CFISD' }, is_shared: false, events: [] },
                sourceWebsite: { url: 'https://www.cfisd.net/calendar', domain: 'www.cfisd.net' },
                summaries: [{ school_year_id: 1, school_year_name: '2026-2027', compatible: true, base_days: 207, removed_days: 0, added_days: 0, scheduled_days: 207 }],
                linkedSources: [{ id: 2, link_id: 8, title: 'District calendar PDF', source_kind: 'upload', source_category: 'calendar', authority_level: 'official_provider', review_status: 'unreviewed', external_url: null, current_file: { id: 4, original_filename: 'calendar.pdf', is_pdf: true }, can_manage: true, can_download: true }],
                suggestedSources: [],
                lifecycle: lifecycle({ linked_source_count: 1 }),
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub, OwnershipBadge: true, CalendarEventRow: true } },
        });
        expect(wrapper.text()).toContain('Source information');
        expect(wrapper.text()).toContain('Open source website');
        expect(wrapper.text()).toContain('Source version: Revision 1');
        expect(wrapper.text()).toContain('Source documents');
        expect(wrapper.text()).toContain('District calendar PDF');
        expect(wrapper.text()).toContain('View PDF');
        expect(wrapper.text()).toContain('Download');
        const external = wrapper.get('a[href="https://www.cfisd.net/calendar"]');
        expect(external.attributes('target')).toBe('_blank');
        expect(external.attributes('rel')).toBe('noopener noreferrer');
    });

    it('always renders source-document empty states and hides adult source controls from read-only UI', () => {
        state.permissions = ['calendars.view'];
        const wrapper = mount(CalendarShow, {
            props: {
                calendar: { id: 1, name: 'Calendar', academic_year_label: '', start_date: '2026-08-12', end_date: '2027-05-27', timezone: 'America/Chicago', status: 'draft', source_version: null, education_provider: null, is_shared: false, events: [] },
                sourceWebsite: null, summaries: [], linkedSources: [], suggestedSources: [], lifecycle: lifecycle({ can_manage: false, can_edit: false, can_archive: false, can_delete: false }),
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub, OwnershipBadge: true } },
        });
        expect(wrapper.text()).toContain('No direct source URL has been recorded.');
        expect(wrapper.text()).toContain('No source documents are linked to this Calendar Profile.');
        expect(wrapper.text()).not.toContain('Source version:');
        expect(wrapper.text()).not.toContain('Edit source information');
        expect(wrapper.text()).not.toContain('Link existing source');
        expect(wrapper.text()).not.toContain('Add new source');
    });

    it('explains that direct URLs and uploaded source documents are managed separately', () => {
        const wrapper = mount(CalendarForm, {
            props: { providers: [], defaults: { timezone: 'America/Chicago' } },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        expect(wrapper.text()).toContain('Official webpage or reference URL');
        expect(wrapper.text()).toContain('Upload PDFs and other files through the Academic Source Library');
        expect(wrapper.text()).toContain('Optional version or publication label');
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

    it('tracks the bulk calendar review as dirty, saves all rows, and blocks stale approval', async () => {
        const wrapper = mount(CalendarImportShow, {
            props: {
                calendarImport: { id: 12, status: 'review_required', extraction_method: 'text', parser_version: 'test', previous_approved_count: 0, proposed_first_day: null, proposed_last_day: null, comparison: null },
                source: { id: 4, title: 'District calendar', file: { name: 'calendar.pdf' } },
                schoolYear: { start_date: '2026-08-12', end_date: '2027-05-27' },
                proposals: [
                    { id: 21, event_date: '2026-09-07', end_date: null, name: 'Holiday', event_type: 'holiday', instructional_effect: 'non_instructional', included: true, parser_note: null, source_page: 1, raw_text: null, warnings: [] },
                    { id: 22, event_date: '2026-10-09', end_date: null, name: 'Workday', event_type: 'teacher_workday', instructional_effect: 'non_instructional', included: true, parser_note: null, source_page: 1, raw_text: null, warnings: [] },
                ],
                eventTypes: ['holiday', 'teacher_workday'], effects: ['non_instructional'], canManage: true, canRetry: false, canDelete: true, linkedEventsCount: 0,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });

        const actionButtons = () => wrapper.findAll('.card.border-primary button');
        expect(actionButtons()[0].attributes('disabled')).toBeDefined();
        expect(wrapper.text()).not.toContain('Unsaved changes');
        expect(wrapper.findAll('button').filter(button => button.text() === 'Save')).toHaveLength(0);

        await wrapper.get('input[aria-label="Include Workday"]').setValue(false);
        await wrapper.get('input[aria-label="Include Holiday"]').setValue(false);
        await wrapper.get('input[aria-label="Include Holiday"]').setValue(true);
        expect(wrapper.text()).toContain('Unsaved changes');
        expect(wrapper.text()).toContain('Save your review changes before approving this import.');
        expect(actionButtons()[0].attributes('disabled')).toBeUndefined();
        expect(actionButtons()[1].attributes('disabled')).toBeDefined();
        await actionButtons()[1].trigger('click');
        expect(state.post).not.toHaveBeenCalled();

        await actionButtons()[0].trigger('click');
        const reviewForm = state.forms.find(form => 'proposals' in form);
        expect(reviewForm?.proposals[21].included).toBe(true);
        expect(reviewForm?.proposals[22].included).toBe(false);
        expect(state.put).toHaveBeenCalledWith('academic.calendar-imports.proposals.bulk-update:12', expect.objectContaining({ preserveScroll: true }));
        expect(state.patch).not.toHaveBeenCalled();

        const saveOptions = state.put.mock.calls[0][1];
        saveOptions.onSuccess();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).not.toContain('Unsaved changes');
        expect(actionButtons()[0].attributes('disabled')).toBeDefined();
        expect(actionButtons()[1].attributes('disabled')).toBeUndefined();
    });

    it('keeps failed bulk edits visible and displays their row-specific errors', async () => {
        state.errors = { 'proposals.22.name': 'The name field is required.' };
        const wrapper = mount(CalendarImportShow, {
            props: {
                calendarImport: { id: 12, status: 'review_required', extraction_method: 'text', parser_version: 'test', previous_approved_count: 0, proposed_first_day: null, proposed_last_day: null, comparison: null },
                source: { id: 4, title: 'District calendar', file: { name: 'calendar.pdf' } },
                schoolYear: { start_date: '2026-08-12', end_date: '2027-05-27' },
                proposals: [{ id: 22, event_date: '2026-10-09', end_date: null, name: 'Workday', event_type: 'teacher_workday', instructional_effect: 'non_instructional', included: true, parser_note: null, source_page: 1, raw_text: null, warnings: [] }],
                eventTypes: ['teacher_workday'], effects: ['non_instructional'], canManage: true, canRetry: false, canDelete: false, linkedEventsCount: 0,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        const name = wrapper.get('tbody tr td:nth-child(3) input');
        await name.setValue('');
        expect((name.element as HTMLInputElement).value).toBe('');
        expect(wrapper.text()).toContain('Review changes were not saved.');
        expect(wrapper.text()).toContain('The name field is required.');
        expect(name.classes()).toContain('is-invalid');
    });

    it('warns before navigating away from unsaved calendar review changes', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mount(CalendarImportShow, {
            props: {
                calendarImport: { id: 12, status: 'review_required', extraction_method: 'text', parser_version: 'test', previous_approved_count: 0, proposed_first_day: null, proposed_last_day: null, comparison: null },
                source: { id: 4, title: 'District calendar', file: { name: 'calendar.pdf' } },
                schoolYear: { start_date: '2026-08-12', end_date: '2027-05-27' },
                proposals: [{ id: 22, event_date: '2026-10-09', end_date: null, name: 'Workday', event_type: 'teacher_workday', instructional_effect: 'non_instructional', included: true, parser_note: null, source_page: 1, raw_text: null, warnings: [] }],
                eventTypes: ['teacher_workday'], effects: ['non_instructional'], canManage: true, canRetry: false, canDelete: false, linkedEventsCount: 0,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });
        await wrapper.get('input[aria-label="Include Workday"]').setValue(false);
        const before = state.routerOn.mock.calls.find(([event]) => event === 'before')?.[1];
        const event = { preventDefault: vi.fn() };
        before?.(event);
        expect(confirm).toHaveBeenCalledWith('You have unsaved review changes. Leave without saving them?');
        expect(event.preventDefault).toHaveBeenCalled();
        wrapper.unmount();
        confirm.mockRestore();
    });

    it('renders an approved calendar import as a read-only summary without an approval button', () => {
        const wrapper = mount(CalendarImportShow, {
            props: {
                calendarImport: { id: 12, status: 'approved', extraction_method: 'text', parser_version: 'test', proposed_first_day: null, proposed_last_day: null, comparison: null, events_created_count: 1, included_count: 1, excluded_count: 1, approved_at: '2026-08-03T12:00:00Z', approved_by: 'Owner User' },
                source: { id: 4, title: 'District calendar', file: { name: 'calendar.pdf' } },
                schoolYear: { start_date: '2026-08-12', end_date: '2027-05-27' },
                proposals: [{ id: 21, event_date: '2026-09-07', end_date: null, name: 'Holiday', event_type: 'holiday', instructional_effect: 'non_instructional', included: true, parser_note: null, source_page: 1, raw_text: null, warnings: [] }],
                eventTypes: ['holiday'], effects: ['non_instructional'], canManage: false, canRetry: false, canDelete: false, linkedEventsCount: 1,
            },
            global: { stubs: { AuthenticatedLayout: layoutStub, AcademicNav: academicNavStub } },
        });

        expect(wrapper.text()).toContain('Approved');
        expect(wrapper.text()).toContain('1 calendar events created');
        expect(wrapper.text()).toContain('Owner User');
        expect(wrapper.text()).toContain('Open live calendar');
        expect(wrapper.text()).not.toContain('Approve import');
        expect(wrapper.text()).not.toContain('Save review changes');
        expect(wrapper.find('button.btn-primary').exists()).toBe(false);
    });
});
