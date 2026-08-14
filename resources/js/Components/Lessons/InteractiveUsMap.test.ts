import { mount, flushPromises } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import InteractiveUsMap from './InteractiveUsMap.vue';

vi.mock('axios');
const geography = {
    type: 'FeatureCollection',
    features: [
        { type: 'Feature', properties: { state_fips: '01', name: 'Alabama' }, geometry: { type: 'Polygon', coordinates: [[[-88.5, 30.2], [-85, 30.2], [-85, 35], [-88.5, 35], [-88.5, 30.2]]] } },
        { type: 'Feature', properties: { state_fips: '06', name: 'California' }, geometry: { type: 'Polygon', coordinates: [[[-124, 32], [-114, 32], [-114, 42], [-124, 42], [-124, 32]]] } },
        { type: 'Feature', properties: { state_fips: '12', name: 'Florida' }, geometry: { type: 'Polygon', coordinates: [[[-87.6, 24.5], [-80, 24.5], [-80, 31], [-87.6, 31], [-87.6, 24.5]]] } },
        { type: 'Feature', properties: { state_fips: '36', name: 'New York' }, geometry: { type: 'Polygon', coordinates: [[[-80, 40], [-72, 40], [-72, 45], [-80, 45], [-80, 40]]] } },
        { type: 'Feature', properties: { state_fips: '40', name: 'Oklahoma' }, geometry: { type: 'Polygon', coordinates: [[[-103, 33.6], [-94.4, 33.6], [-94.4, 37], [-103, 37], [-103, 33.6]]] } },
        { type: 'Feature', properties: { state_fips: '48', name: 'Texas' }, geometry: { type: 'Polygon', coordinates: [[[-106, 26], [-93.5, 26], [-93.5, 36.5], [-106, 36.5], [-106, 26]]] } },
        { type: 'Feature', properties: { state_fips: '56', name: 'Wyoming' }, geometry: { type: 'Polygon', coordinates: [[[-111, 41], [-104, 41], [-104, 45], [-111, 45], [-111, 41]]] } },
    ],
};

describe('Interactive U.S. map', () => {
    beforeEach(() => vi.mocked(axios.get).mockResolvedValue({ data: geography }));

    it('starts label-free and reveals only the selected state name', async () => {
        const wrapper = mount(InteractiveUsMap, { props: { resourceUrl: '/private/map.geojson' } });
        await flushPromises();
        expect(wrapper.findAll('.map-label')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Alabama');
        await wrapper.get('[aria-label="Select Alabama"]').trigger('click');
        expect(wrapper.text()).toContain('Selected state');
        expect(wrapper.text()).toContain('Alabama');
        expect(wrapper.get('[aria-label="Select Alabama"]').attributes('aria-pressed')).toBe('true');
        await wrapper.get('[aria-label="Select Texas"]').trigger('click');
        expect(wrapper.text()).toContain('Texas');
        expect(wrapper.get('[aria-label="Select Alabama"]').attributes('aria-pressed')).toBe('false');
    });

    it('supports keyboard selection with non-color-only feedback', async () => {
        const wrapper = mount(InteractiveUsMap, { props: { resourceUrl: '/private/map.geojson' } });
        await flushPromises();
        await wrapper.get('[aria-label="Select Alabama"]').trigger('keydown', { key: 'Enter' });
        expect(wrapper.get('[aria-label="Select Alabama"]').classes()).toContain('selected');
        expect(wrapper.text()).toContain('striped fill, heavier outline');
    });

    it('moves the instructional focus through map tools one at a time', async () => {
        const wrapper = mount(InteractiveUsMap, { props: { resourceUrl: '/private/map.geojson', mode: 'map_tools' } });
        await flushPromises();
        expect(wrapper.get('.map-frame').attributes('data-active-tool')).toBe('title');
        expect(wrapper.get('.map-title').classes()).toContain('focused');
        await wrapper.get('.tool-buttons button:nth-child(3)').trigger('click');
        expect(wrapper.get('.map-frame').attributes('data-active-tool')).toBe('legend');
        expect(wrapper.get('.map-legend').classes()).toContain('focused');
        await wrapper.get('.tool-nav button:last-child').trigger('click');
        expect(wrapper.get('.map-frame').attributes('data-active-tool')).toBe('labels');
        expect(wrapper.get('.map-label').text()).toContain('Texas');
    });

    it('shows a complete evidence-based reference map without changing authoritative boundaries', async () => {
        const wrapper = mount(InteractiveUsMap, { props: { resourceUrl: '/private/map.geojson', mode: 'reference' } });
        await flushPromises();

        expect(wrapper.text()).toContain('U.S. Census Regions and Selected States');
        expect(wrapper.text()).toContain('Legend');
        expect(wrapper.text()).toContain('Northeast');
        expect(wrapper.text()).toContain('Midwest');
        expect(wrapper.text()).toContain('South');
        expect(wrapper.text()).toContain('West');
        expect(wrapper.text()).toContain('National capital');
        expect(wrapper.text()).toContain('California');
        expect(wrapper.text()).toContain('Texas');
        expect(wrapper.text()).toContain('New York');
        expect(wrapper.find('[aria-label="North arrow"]').exists()).toBe(true);
        expect(wrapper.find('[aria-label*="one thousand miles"]').exists()).toBe(true);
        expect(Number(wrapper.get('.reference-scale line:first-child').attributes('x2'))).toBeGreaterThan(650);
        expect(Number(wrapper.get('.reference-scale line:first-child').attributes('x2'))).toBeLessThan(925);
        expect(wrapper.get('[aria-label="Select California"]').attributes('fill')).toBe('#dadaeb');
        expect(wrapper.get('[aria-label="Select Texas"]').attributes('fill')).toBe('#fdd49e');
        expect(wrapper.find('.tool-teacher').exists()).toBe(false);
    });

    it('builds a structured digital map and verifies its live requirements', async () => {
        const wrapper = mount({
            components: { InteractiveUsMap },
            data: () => ({ map: { title: '', show_orientation: false, features: [
                { state_fips: '', marker_key: '', legend_label: '' },
                { state_fips: '', marker_key: '', legend_label: '' },
                { state_fips: '', marker_key: '', legend_label: '' },
            ] } }),
            template: '<InteractiveUsMap v-model="map" resource-url="/private/map.geojson" mode="builder" />',
        });
        await flushPromises();

        await wrapper.get('.builder-title input').setValue('My Three-State Explorer Map');
        await wrapper.get('.orientation-toggle input').setValue(true);
        const rows = wrapper.findAll('.builder-feature-row');
        for (const [index, values] of [['01', 'blue_circle', 'Family history'], ['06', 'gold_star', 'Dream trip'], ['36', 'green_triangle', 'Famous landmark']].entries()) {
            await rows[index].findAll('select')[0].setValue(values[0]);
            await rows[index].findAll('select')[1].setValue(values[1]);
            await rows[index].get('input').setValue(values[2]);
        }

        const map = (wrapper.vm as any).map;
        expect(map.title).toBe('My Three-State Explorer Map');
        expect(map.show_orientation).toBe(true);
        expect(map.features).toHaveLength(3);
        expect(map.features[1]).toEqual({ state_fips: '06', marker_key: 'gold_star', legend_label: 'Dream trip' });
        expect(wrapper.findAll('.builder-checks li.complete')).toHaveLength(5);
        expect(wrapper.get('.map-title').text()).toContain('My Three-State Explorer Map');
        expect(wrapper.get('.builder-map-features').text()).toContain('California');
        expect(wrapper.get('.builder-legend').text()).toContain('Dream trip');
        expect(wrapper.find('[aria-label="North arrow"]').exists()).toBe(true);
    });

    it('places an authoritative physical relief map beside political evidence', async () => {
        const wrapper = mount(InteractiveUsMap, { props: {
            resourceUrl: '/private/states.geojson', physicalResourceUrl: '/private/usgs-topography.jpg', mode: 'comparison',
        } });
        await flushPromises();

        expect(wrapper.get('.physical-map-card img').attributes('src')).toBe('/private/usgs-topography.jpg');
        expect(wrapper.text()).toContain('Physical Relief of the Contiguous United States');
        expect(wrapper.text()).toContain('Rocky Mountains');
        expect(wrapper.text()).toContain('Great Plains');
        expect(wrapper.text()).toContain('Appalachian Mountains');
        expect(wrapper.text()).toContain('Great Lakes');
        expect(wrapper.text()).toContain('Elevation and relief key');
        expect(wrapper.text()).toContain('Political Map: United States and State Boundaries');
        expect(wrapper.text()).toContain('Political legend');
        expect(wrapper.text()).toContain('U.S. Geological Survey');
    });

    it('shows authoritative population-density evidence beside the physical map', async () => {
        vi.mocked(axios.get).mockImplementation(async (url: string) => ({ data: String(url).includes('population') ? {
            dataset: { caution: 'A mapped pattern does not prove one cause.' },
            states: [
                ['06', 'California', 253.7], ['12', 'Florida', 401.4], ['36', 'New York', 428.7],
                ['48', 'Texas', 111.6], ['56', 'Wyoming', 5.9],
            ].map(([state_fips, name, density_per_sq_mile]) => ({ state_fips, name, density_per_sq_mile })),
        } : geography }));
        const wrapper = mount(InteractiveUsMap, { props: {
            resourceUrl: '/private/states.geojson', populationResourceUrl: '/private/population.json',
            physicalResourceUrl: '/private/usgs-topography.jpg', mode: 'settlement_data',
        } });
        await flushPromises();

        expect(wrapper.text()).toContain('2020 Population Density by State and District of Columbia');
        expect(wrapper.text()).toContain('People per square mile');
        expect(wrapper.text()).toContain('New York428.7 people/mi');
        expect(wrapper.text()).toContain('Wyoming5.9 people/mi');
        expect(wrapper.get('[aria-label="Select New York"]').attributes('fill')).not.toBe(wrapper.get('[aria-label="Select Wyoming"]').attributes('fill'));
        expect(wrapper.get('.physical-map-card img').attributes('src')).toBe('/private/usgs-topography.jpg');
        expect(wrapper.text()).toContain('does not prove one cause');
    });

    it('builds and restores a structured three-region map with live requirements', async () => {
        const wrapper = mount({
            components: { InteractiveUsMap },
            data: () => ({ map: { title: '', criterion: '', regions: [
                { id: 'region_1', name: '', color_key: 'teal', state_fips: ['', ''] },
                { id: 'region_2', name: '', color_key: 'gold', state_fips: ['', ''] },
                { id: 'region_3', name: '', color_key: 'coral', state_fips: ['', ''] },
            ] } }),
            template: '<InteractiveUsMap v-model="map" resource-url="/private/map.geojson" mode="region_builder" />',
        });
        await flushPromises();
        await wrapper.get('.builder-title input').setValue('Three Location Regions');
        await wrapper.findAll('.builder-controls > label')[1].get('input').setValue('relative location from west to east');
        const rows = wrapper.findAll('.region-row');
        const values = [['West', '06', '01'], ['Central', '48', '36'], ['East', '12', '40']];
        for (const [index, region] of values.entries()) {
            await rows[index].get('input').setValue(region[0]);
            await rows[index].findAll('select')[0].setValue(region[1]);
            await rows[index].findAll('select')[1].setValue(region[2]);
        }

        const map = (wrapper.vm as any).map;
        expect(map.criterion).toBe('relative location from west to east');
        expect(map.regions[1]).toEqual({ id: 'region_2', name: 'Central', color_key: 'gold', state_fips: ['48', '36'] });
        expect(wrapper.findAll('.builder-checks li.complete')).toHaveLength(5);
        expect(wrapper.get('.builder-legend').text()).toContain('Central');
    });
});
