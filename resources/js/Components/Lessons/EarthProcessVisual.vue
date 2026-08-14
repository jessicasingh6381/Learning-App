<script setup lang="ts">
defineProps<{ mode: string; photoUrl?: string | null }>();
</script>

<template>
    <figure v-if="mode === 'coastal_change' && photoUrl" class="science-evidence">
        <img :src="photoUrl" alt="USGS aerial photographs of Big Hickory Beach before and after Hurricane Ian; the after view has less vegetation and strips of pale sand across the land." />
        <figcaption>Same Florida coast before and after Hurricane Ian · Source: U.S. Geological Survey · Public domain</figcaption>
    </figure>
    <section v-else-if="mode === 'process_model'" class="process-model" aria-label="Earth process model">
        <div><strong>Weathering</strong><span>rock breaks into sediment</span></div><b>→</b><div><strong>Erosion</strong><span>water, wind, or ice carries sediment</span></div><b>→</b><div><strong>Deposition</strong><span>slower motion drops sediment</span></div><b>→</b><div><strong>Rock formation</strong><span>layers compact and cement over time</span></div>
    </section>
    <section v-else-if="mode === 'cause_chain'" class="cause-chain" aria-label="Rain cause-and-effect model">
        <div>Rain hits exposed rock</div><span>pieces break loose →</span><div>Runoff moves downhill</div><span>water slows →</span><div>Sediment is dropped</div>
    </section>
    <section v-else-if="mode === 'process_cards'" class="process-cards" aria-label="Earth process evidence cards">
        <div><strong>Wind-shaped dune</strong><span>Loose sand grains move through the air.</span></div><div><strong>Glacier carrying rocks</strong><span>Moving ice transports Earth material.</span></div><div><strong>River-mouth deposit</strong><span>Slower water leaves sediment behind.</span></div><div><strong>Layered rock</strong><span>Sediment compacts and cements over time.</span></div>
    </section>
    <section v-else-if="['water_cycle', 'water_cycle_pathway', 'water_cycle_unlabeled'].includes(mode)" class="water-cycle" aria-label="Water-cycle model">
        <div class="sun"><strong>☀ The Sun</strong><span>solar energy</span></div>
        <div class="cloud"><strong>☁ Cloud</strong><span>{{ mode === 'water_cycle_unlabeled' ? '2' : 'condensation: cooling vapor forms droplets' }}</span></div>
        <div class="ocean"><strong>Ocean / collection</strong><span>large water reservoir</span></div>
        <div class="land"><strong>Land, river, and soil</strong><span>runoff returns water; some water infiltrates</span></div>
        <p class="evap">↑ {{ mode === 'water_cycle_unlabeled' ? '1' : 'evaporation' }}</p><p class="rain">↓ {{ mode === 'water_cycle_unlabeled' ? '3' : 'precipitation' }}</p>
    </section>
    <section v-else-if="mode === 'covered_bowl'" class="bowl-model" aria-label="Covered-bowl water-cycle model">
        <div class="ice">4–6 ice cubes</div><div class="wrap">cold plastic wrap → droplets can condense here</div><div class="bowl"><span>warm water</span><b>↑ invisible water vapor</b></div>
    </section>
    <section v-else-if="mode === 'weather_connections'" class="weather-chain" aria-label="Water-cycle and weather connections">
        <div>Solar energy + temperature</div><span>affect</span><div>evaporation and water vapor</div><span>which interact with cooling</span><div>cloud droplets and precipitation</div>
    </section>
    <section v-else-if="mode === 'evaporation_comparison'" class="evaporation-model" aria-label="Controlled evaporation comparison">
        <div><strong>Warmer / sunnier indoor location</strong><span>equal water drop on matching saucer</span></div><div><strong>Cooler shaded location</strong><span>equal water drop on matching saucer</span></div><p>Keep saucers, starting water amount, and observation times the same.</p>
    </section>
</template>

<style scoped>
.science-evidence,.process-model,.cause-chain,.process-cards,.water-cycle,.bowl-model,.weather-chain,.evaporation-model{margin:1.2rem 0;padding:1rem;border:2px solid #b9d7d1;border-radius:16px;background:#f7fbfa}.science-evidence img{display:block;width:100%;border-radius:10px}.science-evidence figcaption{margin-top:.55rem;color:#49656e;font-size:.85rem}.process-model{display:grid;grid-template-columns:repeat(7,auto);align-items:center;gap:.55rem}.process-model div,.process-cards div{display:grid;gap:.3rem;padding:.8rem;border-radius:12px;background:#fff}.process-model b{font-size:1.5rem;color:#317e72}.cause-chain,.weather-chain{display:flex;align-items:center;justify-content:center;gap:.65rem;flex-wrap:wrap}.cause-chain div,.weather-chain div{padding:.8rem 1rem;background:#fff4d8;border-radius:12px;font-weight:800}.cause-chain span,.weather-chain span{font-size:.82rem;color:#49656e}.process-cards,.evaporation-model{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.8rem}.process-cards strong{color:#185b62}.water-cycle{display:grid;grid-template-columns:1fr 1fr;grid-template-areas:'sun cloud' 'evap rain' 'ocean land';gap:.7rem;text-align:center}.water-cycle div{display:grid;gap:.25rem;padding:.8rem;border-radius:12px;background:#fff}.sun{grid-area:sun}.cloud{grid-area:cloud}.ocean{grid-area:ocean;background:#dceff8!important}.land{grid-area:land;background:#e7f2df!important}.evap{grid-area:evap}.rain{grid-area:rain}.evap,.rain{font-weight:900;color:#317e72}.bowl-model{display:grid;text-align:center;gap:.25rem}.ice,.wrap{padding:.55rem;border-radius:10px;background:#dceff8}.bowl{display:grid;gap:.5rem;min-height:130px;padding:1.2rem;border:5px solid #7b9ba8;border-top:0;border-radius:0 0 60px 60px;background:#e9f7fb}.evaporation-model div{display:grid;gap:.35rem;padding:1rem;border-radius:12px;background:#fff}.evaporation-model p{grid-column:1/-1;text-align:center;font-weight:700}@media(max-width:760px){.process-model{grid-template-columns:1fr}.process-model b{text-align:center;transform:rotate(90deg)}.process-cards,.evaporation-model{grid-template-columns:1fr}.water-cycle{grid-template-columns:1fr;grid-template-areas:'sun' 'ocean' 'evap' 'cloud' 'rain' 'land'}}
</style>
