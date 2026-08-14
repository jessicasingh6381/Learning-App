import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import EvidenceAnalysisBuilder from './EvidenceAnalysisBuilder.vue';

describe('Evidence analysis builder', () => {
    it('emits structured work and verifies visible completion requirements', async () => {
        const wrapper = mount({
            components: { EvidenceAnalysisBuilder },
            data: () => ({ analysis: { observations: [{ state_fips: '', statement: '' }, { state_fips: '', statement: '' }], patterns: ['', ''], inference: '', limitation: '' } }),
            template: '<EvidenceAnalysisBuilder v-model="analysis" :config="{ location_choices: [{ state_fips: \'36\', label: \'New York\' }, { state_fips: \'56\', label: \'Wyoming\' }] }" />',
        });
        const rows = wrapper.findAll('.evidence-row');
        await rows[0].get('select').setValue('36');
        await rows[0].get('textarea').setValue('New York shows 428.7 people per square mile.');
        await rows[1].get('select').setValue('56');
        await rows[1].get('textarea').setValue('Wyoming shows 5.9 people per square mile.');
        const patterns = wrapper.findAll('fieldset')[1].findAll('textarea');
        await patterns[0].setValue('New York is much denser than Wyoming.');
        await patterns[1].setValue('The darker state has the larger labeled value.');
        const textareas = wrapper.findAll(':scope > label textarea');
        await textareas[0].setValue('Access to transportation might influence this pattern.');
        await textareas[1].setValue('The maps do not show jobs or historical evidence.');

        expect((wrapper.vm as any).analysis.observations[0].state_fips).toBe('36');
        expect(wrapper.findAll('.analysis-checks li.complete')).toHaveLength(4);
    });
});
