<?php
/**
 * Property-Based Test: DrugInteractionChecker safety invariants
 *
 * **Feature: aichat-safety-hardening (Phase 1 · Workstream A)**
 *
 * `DrugInteractionChecker` is a core safety component of the AI pharmacist
 * pipeline yet had ZERO direct test coverage. These properties lock down the
 * behaviours that must never silently regress: overdose detection, allergy
 * severity classification, condition contraindications, and the aggregate
 * `safe` verdict.
 *
 * The service constructor pulls a DB singleton, but the methods under test
 * (`checkMaxDose`, `checkAllergies`, `checkContraindications`, and
 * `generateSafetyReport` without current-medications) never touch `$this->db`.
 * We therefore instantiate via `newInstanceWithoutConstructor()` to keep the
 * suite pure and DB-free, consistent with the repo's property-test style.
 */

namespace Tests\AIChat;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../../modules/AIChat/Autoloader.php';

use Modules\AIChat\Services\DrugInteractionChecker;

class DrugInteractionCheckerSafetyPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    private DrugInteractionChecker $checker;

    protected function setUp(): void
    {
        // Skip the DB-touching constructor — the methods under test are pure.
        $this->checker = (new ReflectionClass(DrugInteractionChecker::class))
            ->newInstanceWithoutConstructor();
    }

    // --- checkMaxDose ------------------------------------------------------

    /** Any dose strictly above the max (matching unit) is flagged; at/below is not. */
    public function testParacetamolOverdoseBoundary(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $over = mt_rand(4001, 12000);
            $alert = $this->checker->checkMaxDose('paracetamol', (float) $over, 'mg');
            $this->assertIsArray($alert, "dose {$over}mg must exceed 4000mg max");
            $this->assertSame(4000, $alert['max_dose']);
            $this->assertSame((float) $over, $alert['requested_dose']);

            $under = mt_rand(1, 4000);
            $this->assertNull(
                $this->checker->checkMaxDose('paracetamol', (float) $under, 'mg'),
                "dose {$under}mg is within the 4000mg max"
            );
        }
    }

    /** Elderly (>=65) lowers the paracetamol ceiling 4000 -> 3000. */
    public function testElderlyLowersMaxDose(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $dose = mt_rand(3001, 4000); // safe for adults, unsafe for elderly
            $age = mt_rand(65, 100);
            $this->assertNull($this->checker->checkMaxDose('paracetamol', (float) $dose, 'mg'));
            $alert = $this->checker->checkMaxDose('paracetamol', (float) $dose, 'mg', ['age' => $age]);
            $this->assertIsArray($alert, "elderly {$age}y at {$dose}mg must be flagged");
            $this->assertSame(3000, $alert['max_dose']);
        }
    }

    /** Liver disease lowers the paracetamol ceiling to 2000. */
    public function testLiverDiseaseLowersMaxDose(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $dose = mt_rand(2001, 4000);
            $this->assertNull($this->checker->checkMaxDose('paracetamol', (float) $dose, 'mg'));
            $alert = $this->checker->checkMaxDose('paracetamol', (float) $dose, 'mg', ['liver_disease' => true]);
            $this->assertIsArray($alert);
            $this->assertSame(2000, $alert['max_dose']);
        }
    }

    /** A mismatched unit or an unknown drug never triggers an overdose alert. */
    public function testUnitMismatchAndUnknownDrugAreSafe(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $huge = (float) mt_rand(5000, 99999);
            // grams, not mg — unit must match the table entry
            $this->assertNull($this->checker->checkMaxDose('paracetamol', $huge, 'g'));
            // unknown drug
            $this->assertNull($this->checker->checkMaxDose('unobtanium-' . $i, $huge, 'mg'));
        }
    }

    // --- checkAllergies ----------------------------------------------------

    /** A direct allergy match is always CONTRAINDICATED. */
    public function testDirectAllergyIsContraindicated(): void
    {
        $drugs = [['name' => 'Amoxil', 'generic_name' => 'amoxicillin']];
        $warnings = $this->checker->checkAllergies($drugs, ['amoxicillin']);
        $this->assertNotEmpty($warnings);
        $this->assertSame('contraindicated', $warnings[0]['severity']);
        $this->assertSame('direct', $warnings[0]['type']);
    }

    /** Cross-reactivity (penicillin -> amoxicillin) is flagged at least SEVERE. */
    public function testCrossReactivityAllergyIsSevere(): void
    {
        $drugs = [['name' => 'Amoxil', 'generic_name' => 'amoxicillin']];
        $warnings = $this->checker->checkAllergies($drugs, ['penicillin']);
        $this->assertNotEmpty($warnings, 'penicillin allergy must cross-react with amoxicillin');
        $severities = array_column($warnings, 'severity');
        $this->assertTrue(
            in_array('severe', $severities, true) || in_array('contraindicated', $severities, true)
        );
    }

    /** A non-drug allergy against unrelated drugs produces no warnings. */
    public function testUnrelatedAllergyIsSafe(): void
    {
        $drugs = [['name' => 'Paracetamol', 'generic_name' => 'paracetamol']];
        $this->assertSame([], $this->checker->checkAllergies($drugs, ['seafood', 'pollen', 'dust']));
    }

    // --- checkContraindications -------------------------------------------

    /** A condition+drug pair present in the table yields a contraindication. */
    public function testKnownContraindicationIsFlagged(): void
    {
        $drugs = [['name' => 'Sudafed', 'generic_name' => 'pseudoephedrine']];
        $result = $this->checker->checkContraindications($drugs, ['เบาหวาน']);
        $this->assertNotEmpty($result);
        $this->assertSame('เบาหวาน', $result[0]['condition']);
        $this->assertNotEmpty($result[0]['reason']);
    }

    /** A condition not in the table produces no contraindication. */
    public function testUnknownConditionIsSafe(): void
    {
        $drugs = [['name' => 'Sudafed', 'generic_name' => 'pseudoephedrine']];
        $this->assertSame([], $this->checker->checkContraindications($drugs, ['ไข้หวัดธรรมดา']));
    }

    // --- generateSafetyReport (no current_medications => DB-free) ----------

    /** A contraindicated allergy flips the aggregate verdict to unsafe. */
    public function testReportUnsafeOnContraindicatedAllergy(): void
    {
        $report = $this->checker->generateSafetyReport(
            [['name' => 'Amoxil', 'generic_name' => 'amoxicillin']],
            ['allergies' => ['amoxicillin']]
        );
        $this->assertFalse($report['safe']);
        $this->assertNotEmpty($report['allergies']);
    }

    /**
     * Condition contraindications are WARNINGS — they surface in the report but
     * (by design) do not flip `safe` to false on their own.
     */
    public function testReportSafeWithOnlyContraindications(): void
    {
        $report = $this->checker->generateSafetyReport(
            [['name' => 'Sudafed', 'generic_name' => 'pseudoephedrine']],
            ['medical_conditions' => ['เบาหวาน']]
        );
        $this->assertTrue($report['safe']);
        $this->assertNotEmpty($report['contraindications']);
    }

    /** An empty patient profile yields a safe, empty report. */
    public function testEmptyProfileIsSafe(): void
    {
        $report = $this->checker->generateSafetyReport(
            [['name' => 'Paracetamol', 'generic_name' => 'paracetamol']],
            []
        );
        $this->assertTrue($report['safe']);
        $this->assertSame([], $report['interactions']);
        $this->assertSame([], $report['contraindications']);
        $this->assertSame([], $report['allergies']);
    }
}
