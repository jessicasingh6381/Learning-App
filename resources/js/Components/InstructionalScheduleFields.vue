<script setup lang="ts">
interface Props {
    scheduleType: string;
    weekdays: number[];
    typeError?: string;
    weekdaysError?: string;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    'update:scheduleType': [value: string];
    'update:weekdays': [value: number[]];
}>();

const scheduleTypes = [
    { value: 'five_day', label: 'Five-day week' },
    { value: 'four_day', label: 'Four-day week' },
    { value: 'custom', label: 'Custom schedule' },
] as const;

const weekdays = [
    { value: 1, label: 'Monday' },
    { value: 2, label: 'Tuesday' },
    { value: 3, label: 'Wednesday' },
    { value: 4, label: 'Thursday' },
    { value: 5, label: 'Friday' },
    { value: 6, label: 'Saturday' },
    { value: 7, label: 'Sunday' },
] as const;

const presets: Record<string, number[]> = {
    five_day: [1, 2, 3, 4, 5],
    four_day: [1, 2, 3, 4],
};

const changeScheduleType = (event: Event) => {
    const type = (event.target as HTMLSelectElement).value;
    emit('update:scheduleType', type);

    if (presets[type]) {
        emit('update:weekdays', [...presets[type]]);
    }
};

const changeWeekday = (weekday: number, event: Event) => {
    const checked = (event.target as HTMLInputElement).checked;
    const selected = checked
        ? [...props.weekdays, weekday]
        : props.weekdays.filter((value) => value !== weekday);

    emit(
        'update:weekdays',
        [...new Set(selected)].sort((left, right) => left - right),
    );

    if (props.scheduleType !== 'custom') {
        emit('update:scheduleType', 'custom');
    }
};
</script>

<template>
    <fieldset>
        <legend class="h5 mb-3">Instructional schedule</legend>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="instructional_week_type">
                    Weekly schedule
                </label>
                <select
                    id="instructional_week_type"
                    class="form-select"
                    :value="scheduleType"
                    @change="changeScheduleType"
                >
                    <option
                        v-for="type in scheduleTypes"
                        :key="type.value"
                        :value="type.value"
                    >
                        {{ type.label }}
                    </option>
                </select>
                <div class="text-danger small">{{ typeError }}</div>
            </div>
            <div class="col-md-8">
                <div class="form-label">Instructional weekdays</div>
                <div class="d-flex flex-wrap gap-3 pt-2">
                    <div
                        v-for="weekday in weekdays"
                        :key="weekday.value"
                        class="form-check"
                    >
                        <input
                            :id="`instructional_weekday_${weekday.value}`"
                            class="form-check-input"
                            type="checkbox"
                            :value="weekday.value"
                            :checked="props.weekdays.includes(weekday.value)"
                            @change="changeWeekday(weekday.value, $event)"
                        />
                        <label
                            class="form-check-label"
                            :for="`instructional_weekday_${weekday.value}`"
                        >
                            {{ weekday.label }}
                        </label>
                    </div>
                </div>
                <div class="text-danger small">{{ weekdaysError }}</div>
            </div>
        </div>
    </fieldset>
</template>
