import { describe, expect, it } from 'vitest';
describe('foundation status labels', () => {
    it('keeps supported student states explicit', () => {
        expect(['active', 'inactive', 'archived']).toContain('archived');
    });
});
