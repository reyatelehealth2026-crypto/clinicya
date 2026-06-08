interface TestSuiteResult {
    name: string;
    passed: boolean;
    duration: number;
    tests: number;
    failures: number;
    errors: string[];
}
declare function runPropertyBasedTests(): Promise<TestSuiteResult>;
declare function runPerformanceTests(): Promise<TestSuiteResult>;
declare function runLoadTests(): Promise<TestSuiteResult>;
declare function runIntegrationTests(): Promise<TestSuiteResult>;
declare function main(): Promise<void>;
export { main, runPropertyBasedTests, runPerformanceTests, runLoadTests, runIntegrationTests };
//# sourceMappingURL=run-comprehensive-tests.d.ts.map