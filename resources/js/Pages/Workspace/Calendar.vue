<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDateOnly } from '@/Support/dateOnly';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type CalendarEvent = {
    id: number;
    name: string;
    event_date: string;
    end_date: string | null;
    event_type: string;
    instructional_effect: 'instructional' | 'non_instructional' | 'informational';
    description?: string | null;
};

const props = defineProps<{
    schoolYear: null | {
        name: string;
        start_date: string;
        end_date: string;
        instructional_weekdays: number[];
    };
    calendar: {
        state: string;
        target: number | null;
        summary: null | { base_days: number; removed_days: number; added_days: number; scheduled_days: number };
        opening_month: string | null;
        current_date: string;
        profile: null | { id: number; name: string; events: CalendarEvent[] };
        source: null | { name: string; type: string; reference: string | null; version: string | null; review_status: string | null };
    };
    upcomingEvents: CalendarEvent[];
}>();

const page = usePage<any>();
const canAdvanced = page.props.auth.permissions.includes('advanced-academic.view');
const displayedMonth = ref(props.calendar.opening_month ?? props.calendar.current_date.slice(0, 7));
const weekdayHeadings = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

const monthDate = computed(() => {
    const [year, month] = displayedMonth.value.split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, 1));
});
const monthHeading = computed(() => new Intl.DateTimeFormat('en-US', {
    month: 'long', year: 'numeric', timeZone: 'UTC',
}).format(monthDate.value));
const events = computed(() => props.calendar.profile?.events ?? []);

const toDateKey = (date: Date) => date.toISOString().slice(0, 10);
const addMonths = (amount: number) => {
    const next = new Date(Date.UTC(monthDate.value.getUTCFullYear(), monthDate.value.getUTCMonth() + amount, 1));
    displayedMonth.value = toDateKey(next).slice(0, 7);
};
const goToToday = () => {
    displayedMonth.value = props.calendar.opening_month ?? props.calendar.current_date.slice(0, 7);
};

const calendarDays = computed(() => {
    const gridStart = new Date(monthDate.value);
    gridStart.setUTCDate(gridStart.getUTCDate() - gridStart.getUTCDay());

    return Array.from({ length: 42 }, (_, offset) => {
        const date = new Date(gridStart);
        date.setUTCDate(gridStart.getUTCDate() + offset);
        const key = toDateKey(date);
        const dateEvents = events.value.filter(event => event.event_date <= key && (event.end_date ?? event.event_date) >= key);
        const isoWeekday = date.getUTCDay() === 0 ? 7 : date.getUTCDay();
        const instructionalOverride = dateEvents.some(event => event.instructional_effect === 'instructional');
        const closure = dateEvents.some(event => event.instructional_effect === 'non_instructional');
        const inSchoolYear = !!props.schoolYear && key >= props.schoolYear.start_date && key <= props.schoolYear.end_date;
        const instructional = inSchoolYear && (instructionalOverride || (!closure && props.schoolYear!.instructional_weekdays.includes(isoWeekday)));

        return {
            key,
            day: date.getUTCDate(),
            inMonth: date.getUTCMonth() === monthDate.value.getUTCMonth(),
            inSchoolYear,
            boundaryLabel: key === props.schoolYear?.start_date
                ? 'First day of school'
                : key === props.schoolYear?.end_date
                    ? 'Last day of school'
                    : null,
            isToday: key === props.calendar.current_date,
            instructional,
            events: dateEvents,
        };
    });
});

const eventLabel = (type: string) => ({
    holiday: 'Holiday',
    first_day: 'First day of school',
    last_day: 'Last day of school',
    break: 'School break',
    student_holiday: 'Student holiday',
    teacher_workday: 'Teacher workday',
    staff_development: 'Professional development',
    professional_development: 'Professional development',
    weather_closure: 'Weather closure',
    tenant_day_off: 'School closure',
    district_closure: 'School closure',
    school_closure: 'School closure',
    early_release: 'Early release',
    instructional_makeup_day: 'Added instructional day',
    instructional_override: 'Added instructional day',
    makeup_day: 'Added instructional day',
    other: 'Calendar event',
}[type] ?? type.replaceAll('_', ' '));

const eventClass = (event: CalendarEvent) => {
    if (['instructional_makeup_day', 'instructional_override', 'makeup_day'].includes(event.event_type)) return 'calendar-event-added';
    if (['first_day', 'last_day'].includes(event.event_type)) return 'calendar-event-boundary';
    if (event.event_type === 'early_release') return 'calendar-event-early';
    if (['teacher_workday', 'staff_development', 'professional_development'].includes(event.event_type)) return 'calendar-event-teacher';
    if (event.event_type === 'student_holiday') return 'calendar-event-student';
    if (['holiday', 'break', 'weather_closure', 'school_closure', 'tenant_day_off', 'district_closure'].includes(event.event_type)) return 'calendar-event-closure';
    return 'calendar-event-other';
};

const firstMonth = computed(() => props.schoolYear?.start_date.slice(0, 7));
const lastMonth = computed(() => props.schoolYear?.end_date.slice(0, 7));
</script>

<template>
    <Head title="Calendar" />
    <AuthenticatedLayout>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <h1 class="h2">Calendar</h1>
                <p class="text-secondary mb-0">School days, closures, and important dates at a glance.</p>
            </div>
            <Link v-if="canAdvanced" class="btn btn-outline-secondary" :href="route('academic.calendars.index')">Advanced calendar setup</Link>
        </div>

        <div v-if="!schoolYear" class="card">
            <div class="empty-state">Choose an active school year to view its calendar.</div>
        </div>

        <template v-else>
            <section class="card mb-4" aria-labelledby="calendar-source-heading">
                <div class="card-body py-3">
                    <h2 id="calendar-source-heading" class="h6 mb-1">Calendar source</h2>
                    <p v-if="calendar.source" class="mb-0">
                        <strong>{{ calendar.source.name }}</strong>
                        <span v-if="calendar.source.version" class="text-secondary"> · {{ calendar.source.version }}</span>
                        <span v-if="calendar.source.reference" class="d-block small text-secondary text-break">{{ calendar.source.reference }}</span>
                    </p>
                    <p v-else class="text-secondary mb-0">No external calendar source has been imported.</p>
                </div>
            </section>

            <div v-if="calendar.state !== 'ready'" class="alert alert-warning">
                No compatible calendar profile is selected. The weekly schedule is shown, but saved calendar exceptions cannot be applied.
            </div>

            <section class="card calendar-card mb-4" aria-labelledby="calendar-month-heading">
                <div class="card-header bg-body d-flex flex-wrap align-items-center justify-content-between gap-3 py-3">
                    <div class="btn-group" role="group" aria-label="Calendar navigation">
                        <button class="btn btn-outline-secondary" type="button" aria-label="Previous month" :disabled="displayedMonth <= firstMonth!" @click="addMonths(-1)">‹</button>
                        <button class="btn btn-outline-secondary" type="button" @click="goToToday">Today</button>
                        <button class="btn btn-outline-secondary" type="button" aria-label="Next month" :disabled="displayedMonth >= lastMonth!" @click="addMonths(1)">›</button>
                    </div>
                    <h2 id="calendar-month-heading" class="h4 mb-0">{{ monthHeading }}</h2>
                    <span class="small text-secondary">{{ schoolYear.name }}</span>
                </div>

                <div class="calendar-scroll">
                    <div class="calendar-grid calendar-weekdays" role="row">
                        <div v-for="heading in weekdayHeadings" :key="heading" class="calendar-weekday" role="columnheader">{{ heading }}</div>
                    </div>
                    <div class="calendar-grid" role="grid" :aria-label="monthHeading">
                        <div
                            v-for="day in calendarDays"
                            :key="day.key"
                            class="calendar-day"
                            :class="{
                                'calendar-day-muted': !day.inMonth || !day.inSchoolYear,
                                'calendar-day-instructional': day.instructional,
                                'calendar-day-non-instructional': day.inSchoolYear && !day.instructional,
                                'calendar-day-today': day.isToday,
                            }"
                            role="gridcell"
                            :aria-label="day.key"
                        >
                            <time class="calendar-day-number" :datetime="day.key">{{ day.day }}</time>
                            <div class="calendar-events">
                                <span
                                    v-for="event in day.events"
                                    :key="event.id"
                                    class="calendar-event"
                                    :class="eventClass(event)"
                                    :title="`${eventLabel(event.event_type)}: ${event.name}`"
                                >{{ event.name }}</span>
                                <span v-if="day.boundaryLabel" class="calendar-event calendar-event-boundary">{{ day.boundaryLabel }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mb-4" aria-labelledby="calendar-legend-heading">
                <h2 id="calendar-legend-heading" class="h6">Legend</h2>
                <div class="d-flex flex-wrap gap-3 small">
                    <span><i class="legend-swatch calendar-day-instructional" />Instructional day</span>
                    <span><i class="legend-swatch calendar-day-non-instructional" />Weekend / non-instructional</span>
                    <span><i class="legend-swatch calendar-event-closure" />Holiday / closure</span>
                    <span><i class="legend-swatch calendar-event-student" />Student holiday</span>
                    <span><i class="legend-swatch calendar-event-teacher" />Teacher workday / development</span>
                    <span><i class="legend-swatch calendar-event-early" />Early release</span>
                    <span><i class="legend-swatch calendar-event-added" />Added instructional day</span>
                    <span><i class="legend-swatch calendar-event-other" />Other event</span>
                </div>
            </section>

            <section class="card mb-4" aria-labelledby="upcoming-events-heading">
                <div class="card-body">
                    <h2 id="upcoming-events-heading" class="h4">Upcoming events</h2>
                    <p v-if="!upcomingEvents.length" class="text-secondary mb-0">No upcoming calendar events have been saved.</p>
                    <ul v-else class="list-group list-group-flush">
                        <li v-for="event in upcomingEvents" :key="event.id" class="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2">
                            <span><strong>{{ event.name }}</strong><small class="d-block text-secondary">{{ eventLabel(event.event_type) }}</small></span>
                            <span>{{ formatDateOnly(event.event_date) }}<template v-if="event.end_date"> – {{ formatDateOnly(event.end_date) }}</template></span>
                        </li>
                    </ul>
                </div>
            </section>

            <details class="card mb-4">
                <summary class="card-header bg-body fw-semibold py-3">Calendar summary and planning details</summary>
                <div class="card-body">
                    <p class="text-secondary">{{ formatDateOnly(schoolYear.start_date) }} – {{ formatDateOnly(schoolYear.end_date) }} · {{ schoolYear.instructional_weekdays.length }} configured instructional days per week</p>
                    <div v-if="calendar.summary" class="row g-3 mb-3">
                        <div v-for="metric in [['Base days', calendar.summary.base_days], ['Removed days', calendar.summary.removed_days], ['Added days', calendar.summary.added_days], ['Scheduled days', calendar.summary.scheduled_days]]" :key="metric[0]" class="col-6 col-lg-3">
                            <div class="border rounded p-3 h-100"><span class="text-secondary">{{ metric[0] }}</span><strong class="h3 d-block mb-0">{{ metric[1] }}</strong></div>
                        </div>
                    </div>
                    <div v-if="calendar.summary" class="alert alert-info mb-0">
                        <strong>Instructional-day target: {{ calendar.target ?? 'Not set' }}</strong>
                        <span v-if="calendar.target !== null && calendar.target !== calendar.summary.scheduled_days" class="d-block">This saved planning target differs from the calculated schedule. It has not been changed automatically.</span>
                    </div>
                </div>
            </details>
        </template>
    </AuthenticatedLayout>
</template>

<style scoped>
.calendar-scroll { overflow-x: auto; }
.calendar-grid { display: grid; grid-template-columns: repeat(7, minmax(7.5rem, 1fr)); min-width: 52.5rem; }
.calendar-weekday { padding: .65rem; border-bottom: 1px solid var(--bs-border-color); color: var(--bs-secondary-color); font-size: .8rem; font-weight: 700; text-align: center; text-transform: uppercase; }
.calendar-day { min-height: 7.5rem; padding: .45rem; border-right: 1px solid var(--bs-border-color); border-bottom: 1px solid var(--bs-border-color); background: var(--bs-body-bg); }
.calendar-day:nth-child(7n) { border-right: 0; }
.calendar-day-instructional { background: color-mix(in srgb, var(--bs-success) 7%, var(--bs-body-bg)); }
.calendar-day-non-instructional { background: color-mix(in srgb, var(--bs-secondary) 9%, var(--bs-body-bg)); }
.calendar-day-muted { opacity: .48; }
.calendar-day-today { box-shadow: inset 0 0 0 2px var(--bs-primary); }
.calendar-day-number { display: inline-grid; min-width: 1.6rem; height: 1.6rem; place-items: center; font-weight: 700; }
.calendar-day-today .calendar-day-number { border-radius: 50%; color: white; background: var(--bs-primary); }
.calendar-events { display: grid; gap: .2rem; margin-top: .35rem; }
.calendar-event { display: block; overflow: hidden; padding: .15rem .3rem; border-left: .25rem solid; border-radius: .2rem; font-size: .72rem; font-weight: 600; line-height: 1.25; text-overflow: ellipsis; white-space: nowrap; }
.calendar-event-closure { border-color: var(--bs-danger); background: color-mix(in srgb, var(--bs-danger) 15%, var(--bs-body-bg)); }
.calendar-event-student { border-color: var(--bs-warning); background: color-mix(in srgb, var(--bs-warning) 20%, var(--bs-body-bg)); }
.calendar-event-teacher { border-color: var(--bs-purple, #6f42c1); background: color-mix(in srgb, #6f42c1 14%, var(--bs-body-bg)); }
.calendar-event-early { border-color: var(--bs-info); background: color-mix(in srgb, var(--bs-info) 18%, var(--bs-body-bg)); }
.calendar-event-added { border-color: var(--bs-success); background: color-mix(in srgb, var(--bs-success) 16%, var(--bs-body-bg)); }
.calendar-event-boundary { border-color: var(--bs-primary); background: color-mix(in srgb, var(--bs-primary) 14%, var(--bs-body-bg)); }
.calendar-event-other { border-color: var(--bs-secondary); background: color-mix(in srgb, var(--bs-secondary) 14%, var(--bs-body-bg)); }
.legend-swatch { display: inline-block; width: 1rem; height: 1rem; margin-right: .35rem; border: 1px solid var(--bs-border-color); border-left-width: .25rem; border-radius: .15rem; vertical-align: -.15rem; }
summary { cursor: pointer; }
@media (max-width: 767.98px) { .calendar-day { min-height: 6.5rem; } }
</style>
