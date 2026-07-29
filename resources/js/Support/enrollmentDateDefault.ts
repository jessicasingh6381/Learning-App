import { ref, watch, type Ref } from 'vue';

export interface EnrollmentSchoolYear {
    id: number;
    name: string;
    start_date: string;
    end_date: string;
}

interface EnrollmentDateForm {
    school_year_id: number | string;
    enrollment_date: string;
}

export function useEnrollmentDateDefault(
    form: EnrollmentDateForm,
    schoolYears: EnrollmentSchoolYear[],
    hasOldInput: boolean,
): {
    automaticEnrollmentDate: Ref<string | null>;
    markEnrollmentDateAsManual: () => void;
} {
    const automaticEnrollmentDate = ref<string | null>(null);

    const startDateFor = (schoolYearId: number | string): string => {
        return (
            schoolYears.find(
                (schoolYear) =>
                    String(schoolYear.id) === String(schoolYearId),
            )?.start_date ?? ''
        );
    };

    if (!hasOldInput && !form.enrollment_date && form.school_year_id) {
        const startDate = startDateFor(form.school_year_id);

        if (startDate) {
            form.enrollment_date = startDate;
            automaticEnrollmentDate.value = startDate;
        }
    }

    watch(
        () => form.school_year_id,
        (schoolYearId) => {
            const nextStartDate = startDateFor(schoolYearId);
            const stillUsingAutomaticDate =
                automaticEnrollmentDate.value !== null &&
                form.enrollment_date === automaticEnrollmentDate.value;

            if (!form.enrollment_date || stillUsingAutomaticDate) {
                form.enrollment_date = nextStartDate;
                automaticEnrollmentDate.value = nextStartDate || null;
            }
        },
    );

    const markEnrollmentDateAsManual = () => {
        automaticEnrollmentDate.value = null;
    };

    return {
        automaticEnrollmentDate,
        markEnrollmentDateAsManual,
    };
}
