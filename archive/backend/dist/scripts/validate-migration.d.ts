#!/usr/bin/env tsx
interface ValidationResult {
    test: string;
    passed: boolean;
    duration: number;
    error?: string;
    details?: any;
}
declare class MigrationValidator {
    private results;
    validateDataIntegrity(): Promise<ValidationResult>;
    validateForeignKeyRelationships(): Promise<ValidationResult>;
    validateIndexPerformance(): Promise<ValidationResult>;
    validateCacheTableStructure(): Promise<ValidationResult>;
    runAllValidations(): Promise<void>;
}
export { MigrationValidator };
//# sourceMappingURL=validate-migration.d.ts.map