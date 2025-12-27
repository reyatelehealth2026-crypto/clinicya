<?php
/**
 * LIFF Symptom Assessment - แบบประเมินอาการเบื้องต้น
 * ผู้ใช้กรอกอาการ -> AI วิเคราะห์ -> แสดงผลพร้อมแหล่งอ้างอิง
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = Database::getInstance()->getConnection();

// ดึง LIFF ID
$liffId = '';
$lineAccountId = isset($_GET['line_account_id']) ? intval($_GET['line_account_id']) : null;

if ($lineAccountId) {
    // ดึงจาก line_accounts ตาม ID ที่ระบุ
    $stmt = $db->prepare("SELECT liff_id FROM line_accounts WHERE id = ?");
    $stmt->execute([$lineAccountId]);
    $liffId = $stmt->fetchColumn() ?: '';
}

// ถ้าไม่มี LIFF ID ให้ดึงจาก default account
if (empty($liffId)) {
    $stmt = $db->prepare("SELECT id, liff_id FROM line_accounts WHERE is_default = 1 LIMIT 1");
    $stmt->execute();
    $defaultAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($defaultAccount) {
        $liffId = $defaultAccount['liff_id'] ?: '';
        $lineAccountId = $defaultAccount['id'];
    }
}

// ถ้ายังไม่มี ให้ดึงจาก account แรก
if (empty($liffId)) {
    $stmt = $db->prepare("SELECT id, liff_id FROM line_accounts ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $firstAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($firstAccount) {
        $liffId = $firstAccount['liff_id'] ?: '';
        $lineAccountId = $firstAccount['id'];
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ประเมินอาการเบื้องต้น - ตรวจสอบอาการก่อนพบแพทย์</title>
    <meta name="description" content="ประเมินอาการเบื้องต้นออนไลน์ ตรวจสอบอาการเจ็บป่วย รับคำแนะนำจากระบบ AI และเภสัชกร ก่อนตัดสินใจพบแพทย์">
    <meta name="keywords" content="ประเมินอาการ, ตรวจอาการ, เช็คอาการ, อาการเจ็บป่วย, ปรึกษาเภสัชกร">
    <meta name="robots" content="index, follow">
    <meta property="og:title" content="ประเมินอาการเบื้องต้น">
    <meta property="og:description" content="ตรวจสอบอาการเจ็บป่วย รับคำแนะนำจากระบบ AI และเภสัชกร">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="th_TH">
    <script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #06C755;
            --danger: #dc3545;
            --warning: #ffc107;
        }
        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding-bottom: 100px;
        }
        .header {
            background: linear-gradient(135deg, var(--primary), #05a647);
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 0 0 20px 20px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin: 15px;
        }
        .card-header {
            background: white;
            border-bottom: 2px solid #f0f0f0;
            font-weight: bold;
            padding: 15px 20px;
            border-radius: 15px 15px 0 0 !important;
        }
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        .symptom-btn {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 10px 15px;
            margin: 5px;
            background: white;
            transition: all 0.3s;
        }
        .symptom-btn.active {
            border-color: var(--primary);
            background: #e8f5e9;
            color: var(--primary);
        }
        .severity-slider {
            -webkit-appearance: none;
            width: 100%;
            height: 10px;
            border-radius: 5px;
            background: linear-gradient(to right, #4caf50, #ffeb3b, #f44336);
        }
        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 25px;
            padding: 15px 40px;
            font-size: 18px;
            width: 100%;
        }
        .result-card {
            display: none;
        }
        .risk-badge {
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 18px;
        }
        .risk-low { background: #c8e6c9; color: #2e7d32; }
        .risk-medium { background: #fff9c4; color: #f57f17; }
        .risk-high { background: #ffcdd2; color: #c62828; }
        .reference-card {
            background: #f8f9fa;
            border-left: 4px solid var(--primary);
            padding: 15px;
            margin: 10px 0;
            border-radius: 0 10px 10px 0;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        .spinner {
            width: 60px;
            height: 60px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p class="mt-3">🔬 กำลังวิเคราะห์อาการ...</p>
    </div>

    <!-- Header -->
    <div class="header">
        <h4><i class="fas fa-stethoscope"></i> ประเมินอาการเบื้องต้น</h4>
        <small>กรอกข้อมูลเพื่อรับคำแนะนำจาก AI เภสัชกร</small>
    </div>

    <!-- Assessment Form -->
    <div id="assessmentForm">
        <!-- ข้อมูลพื้นฐาน -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user text-primary"></i> ข้อมูลพื้นฐาน
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">เพศ</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="male" value="ชาย">
                            <label class="form-check-label" for="male">ชาย</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="gender" id="female" value="หญิง">
                            <label class="form-check-label" for="female">หญิง</label>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">อายุ (ปี)</label>
                    <input type="number" class="form-control" id="age" placeholder="เช่น 35" min="1" max="120">
                </div>
                <div class="mb-3">
                    <label class="form-label">น้ำหนัก (กก.)</label>
                    <input type="number" class="form-control" id="weight" placeholder="เช่น 65" min="1" max="300">
                </div>
            </div>
        </div>

        <!-- อาการหลัก -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-heartbeat text-danger"></i> อาการหลักที่มา
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">อาการหลัก (เลือกได้หลายข้อ)</label>
                    <div id="mainSymptoms" class="d-flex flex-wrap">
                        <button type="button" class="symptom-btn" data-symptom="ปวดหัว">🤕 ปวดหัว</button>
                        <button type="button" class="symptom-btn" data-symptom="ไข้">🌡️ ไข้</button>
                        <button type="button" class="symptom-btn" data-symptom="ไอ">😷 ไอ</button>
                        <button type="button" class="symptom-btn" data-symptom="เจ็บคอ">🗣️ เจ็บคอ</button>
                        <button type="button" class="symptom-btn" data-symptom="น้ำมูก">🤧 น้ำมูก</button>
                        <button type="button" class="symptom-btn" data-symptom="ปวดท้อง">🤢 ปวดท้อง</button>
                        <button type="button" class="symptom-btn" data-symptom="ท้องเสีย">💩 ท้องเสีย</button>
                        <button type="button" class="symptom-btn" data-symptom="คลื่นไส้">🤮 คลื่นไส้</button>
                        <button type="button" class="symptom-btn" data-symptom="ผื่นคัน">🔴 ผื่นคัน</button>
                        <button type="button" class="symptom-btn" data-symptom="เวียนหัว">😵 เวียนหัว</button>
                        <button type="button" class="symptom-btn" data-symptom="อ่อนเพลีย">😴 อ่อนเพลีย</button>
                        <button type="button" class="symptom-btn" data-symptom="ปวดกล้ามเนื้อ">💪 ปวดกล้ามเนื้อ</button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">อาการอื่นๆ (ถ้ามี)</label>
                    <textarea class="form-control" id="otherSymptoms" rows="2" placeholder="อธิบายอาการเพิ่มเติม..."></textarea>
                </div>
            </div>
        </div>

        <!-- ความรุนแรง -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-line text-warning"></i> รายละเอียดอาการ
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">ระดับความรุนแรง (1-10)</label>
                    <input type="range" class="severity-slider" id="severity" min="1" max="10" value="5">
                    <div class="d-flex justify-content-between">
                        <small class="text-success">เล็กน้อย</small>
                        <span id="severityValue" class="fw-bold">5</span>
                        <small class="text-danger">รุนแรงมาก</small>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">อาการเป็นมานานเท่าไร?</label>
                    <select class="form-select" id="duration">
                        <option value="">-- เลือก --</option>
                        <option value="ไม่กี่ชั่วโมง">ไม่กี่ชั่วโมง</option>
                        <option value="1 วัน">1 วัน</option>
                        <option value="2-3 วัน">2-3 วัน</option>
                        <option value="4-7 วัน">4-7 วัน</option>
                        <option value="มากกว่า 1 สัปดาห์">มากกว่า 1 สัปดาห์</option>
                        <option value="มากกว่า 1 เดือน">มากกว่า 1 เดือน</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">อาการเป็นตอนไหน?</label>
                    <select class="form-select" id="timing">
                        <option value="">-- เลือก --</option>
                        <option value="ตลอดเวลา">ตลอดเวลา</option>
                        <option value="เป็นๆ หายๆ">เป็นๆ หายๆ</option>
                        <option value="ตอนเช้า">ตอนเช้า</option>
                        <option value="ตอนกลางคืน">ตอนกลางคืน</option>
                        <option value="หลังทานอาหาร">หลังทานอาหาร</option>
                        <option value="เมื่อออกแรง">เมื่อออกแรง</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">มีปัจจัยกระตุ้นหรือไม่?</label>
                    <input type="text" class="form-control" id="triggers" placeholder="เช่น อากาศเปลี่ยน, เครียด, อาหาร">
                </div>
            </div>
        </div>

        <!-- ประวัติสุขภาพ -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-notes-medical text-info"></i> ประวัติสุขภาพ
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">โรคประจำตัว (ถ้ามี)</label>
                    <div class="d-flex flex-wrap" id="conditions">
                        <button type="button" class="symptom-btn" data-condition="เบาหวาน">🩸 เบาหวาน</button>
                        <button type="button" class="symptom-btn" data-condition="ความดันสูง">❤️ ความดันสูง</button>
                        <button type="button" class="symptom-btn" data-condition="หัวใจ">💓 โรคหัวใจ</button>
                        <button type="button" class="symptom-btn" data-condition="ไต">🫘 โรคไต</button>
                        <button type="button" class="symptom-btn" data-condition="หอบหืด">🌬️ หอบหืด</button>
                        <button type="button" class="symptom-btn" data-condition="ภูมิแพ้">🤧 ภูมิแพ้</button>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">แพ้ยา (ถ้ามี)</label>
                    <input type="text" class="form-control" id="allergies" placeholder="เช่น แอสไพริน, เพนนิซิลิน">
                </div>
                <div class="mb-3">
                    <label class="form-label">ยาที่ใช้อยู่ประจำ (ถ้ามี)</label>
                    <input type="text" class="form-control" id="medications" placeholder="เช่น ยาลดความดัน, ยาเบาหวาน">
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="p-3">
            <button type="button" class="btn-submit" onclick="submitAssessment()">
                <i class="fas fa-search-plus"></i> วิเคราะห์อาการ
            </button>
        </div>
    </div>

    <!-- Result Section -->
    <div id="resultSection" class="result-card">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-clipboard-check text-success"></i> ผลการประเมิน
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <span id="riskBadge" class="risk-badge"></span>
                </div>
                <div id="analysisResult"></div>
            </div>
        </div>

        <!-- แหล่งอ้างอิง -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-book-medical text-primary"></i> แหล่งอ้างอิงทางการแพทย์
            </div>
            <div class="card-body" id="references"></div>
        </div>

        <!-- คำแนะนำยา -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-pills text-success"></i> ยาที่แนะนำ
            </div>
            <div class="card-body" id="recommendedMeds"></div>
        </div>

        <!-- Actions -->
        <div class="p-3">
            <button class="btn btn-outline-primary w-100 mb-2" onclick="shareResult()">
                <i class="fas fa-share"></i> แชร์ผลประเมิน
            </button>
            <button class="btn btn-outline-secondary w-100 mb-2" onclick="newAssessment()">
                <i class="fas fa-redo"></i> ประเมินใหม่
            </button>
            <button class="btn btn-success w-100" onclick="consultPharmacist()">
                <i class="fas fa-user-md"></i> ปรึกษาเภสัชกร
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const LIFF_ID = '<?php echo $liffId; ?>';
        const LINE_ACCOUNT_ID = <?php echo $lineAccountId ? $lineAccountId : 'null'; ?>;
        let liffProfile = null;
        let assessmentData = {};
        let assessmentResult = null;

        // Initialize LIFF
        document.addEventListener('DOMContentLoaded', async () => {
            // Initialize LIFF only if we have a valid LIFF ID
            if (LIFF_ID && LIFF_ID.length > 0) {
                try {
                    await liff.init({ liffId: LIFF_ID });
                    if (liff.isLoggedIn()) {
                        liffProfile = await liff.getProfile();
                    }
                    console.log('LIFF initialized successfully');
                } catch (e) {
                    console.log('LIFF init error:', e);
                }
            } else {
                console.log('No LIFF ID configured, running in standalone mode');
            }

            // Symptom buttons
            document.querySelectorAll('.symptom-btn').forEach(btn => {
                btn.addEventListener('click', () => btn.classList.toggle('active'));
            });

            // Severity slider
            document.getElementById('severity').addEventListener('input', (e) => {
                document.getElementById('severityValue').textContent = e.target.value;
            });
        });

        // Submit Assessment
        async function submitAssessment() {
            // Collect data
            const selectedSymptoms = [];
            document.querySelectorAll('#mainSymptoms .symptom-btn.active').forEach(btn => {
                selectedSymptoms.push(btn.dataset.symptom);
            });

            const selectedConditions = [];
            document.querySelectorAll('#conditions .symptom-btn.active').forEach(btn => {
                selectedConditions.push(btn.dataset.condition);
            });

            if (selectedSymptoms.length === 0) {
                alert('กรุณาเลือกอาการอย่างน้อย 1 อาการ');
                return;
            }

            assessmentData = {
                gender: document.querySelector('input[name="gender"]:checked')?.value || '',
                age: document.getElementById('age').value,
                weight: document.getElementById('weight').value,
                symptoms: selectedSymptoms,
                otherSymptoms: document.getElementById('otherSymptoms').value,
                severity: document.getElementById('severity').value,
                duration: document.getElementById('duration').value,
                timing: document.getElementById('timing')?.value || '',
                triggers: document.getElementById('triggers')?.value || '',
                conditions: selectedConditions,
                allergies: document.getElementById('allergies').value,
                medications: document.getElementById('medications').value,
                userId: liffProfile?.userId || null,
                lineUserId: liffProfile?.userId || null,
                lineAccountId: LINE_ACCOUNT_ID
            };

            // Show loading
            document.getElementById('loadingOverlay').style.display = 'flex';

            try {
                const response = await fetch('api/symptom-assessment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(assessmentData)
                });

                const result = await response.json();
                
                if (result.success) {
                    assessmentResult = result;
                    displayResult(result);
                } else {
                    alert('เกิดข้อผิดพลาด: ' + (result.error || 'ไม่สามารถวิเคราะห์ได้'));
                }
            } catch (e) {
                console.error(e);
                alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            } finally {
                document.getElementById('loadingOverlay').style.display = 'none';
            }
        }

        // Display Result
        function displayResult(result) {
            document.getElementById('assessmentForm').style.display = 'none';
            document.getElementById('resultSection').style.display = 'block';

            // Risk Level
            const riskBadge = document.getElementById('riskBadge');
            const riskLevel = result.riskLevel || 'low';
            const riskText = {
                'low': '🟢 ความเสี่ยงต่ำ',
                'medium': '🟡 ความเสี่ยงปานกลาง',
                'high': '🔴 ความเสี่ยงสูง'
            };
            riskBadge.textContent = riskText[riskLevel] || riskText['low'];
            riskBadge.className = 'risk-badge risk-' + riskLevel;

            // Analysis - แสดงผลละเอียด
            let analysisHtml = `
                <div class="mb-4">
                    <h6>📋 การวิเคราะห์อาการ:</h6>
                    <p class="mb-3" style="line-height: 1.8;">${result.analysis || ''}</p>
                </div>
            `;
            
            // สาเหตุที่เป็นไปได้
            if (result.possibleCauses && result.possibleCauses.length > 0) {
                analysisHtml += `
                    <div class="mb-4 p-3 bg-light rounded">
                        <h6>🔍 สาเหตุที่เป็นไปได้:</h6>
                        <ol class="mb-0">
                            ${result.possibleCauses.map(cause => `<li class="mb-2">${cause}</li>`).join('')}
                        </ol>
                    </div>
                `;
            }
            
            analysisHtml += `
                <div class="mb-4">
                    <h6>💡 คำแนะนำการดูแลตัวเอง:</h6>
                    <p style="line-height: 1.8;">${result.recommendation || ''}</p>
                </div>
            `;
            
            // วิธีดูแลตัวเอง
            if (result.selfCare && result.selfCare.length > 0) {
                analysisHtml += `
                    <div class="mb-4 p-3" style="background: #e3f2fd; border-radius: 10px;">
                        <h6>🏠 วิธีดูแลตัวเองที่บ้าน:</h6>
                        <ul class="mb-0">
                            ${result.selfCare.map(item => `<li class="mb-1">${item}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
            
            // อาการเตือน
            if (result.warningSignals && result.warningSignals.length > 0) {
                analysisHtml += `
                    <div class="mb-4 p-3" style="background: #ffebee; border-radius: 10px;">
                        <h6>⚠️ อาการเตือนที่ต้องพบแพทย์ทันที:</h6>
                        <ul class="mb-0 text-danger">
                            ${result.warningSignals.map(item => `<li class="mb-1">${item}</li>`).join('')}
                        </ul>
                    </div>
                `;
            }
            
            // การติดตามอาการ
            if (result.followUp) {
                analysisHtml += `
                    <div class="mb-3 p-3 bg-light rounded">
                        <h6>📅 การติดตามอาการ:</h6>
                        <p class="mb-0">${result.followUp}</p>
                    </div>
                `;
            }
            
            // คำเตือน
            if (result.warning) {
                analysisHtml += `<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> <strong>คำเตือน:</strong> ${result.warning}</div>`;
            }
            
            document.getElementById('analysisResult').innerHTML = analysisHtml;

            // References
            const refsHtml = (result.references || []).map(ref => `
                <div class="reference-card">
                    <strong>${ref.title}</strong>
                    <p class="mb-1 small text-muted">${ref.source}</p>
                    ${ref.url ? `<a href="${ref.url}" target="_blank" class="small">อ่านเพิ่มเติม →</a>` : ''}
                </div>
            `).join('');
            document.getElementById('references').innerHTML = refsHtml || '<p class="text-muted">ไม่มีข้อมูลอ้างอิง</p>';

            // Recommended Medications - แสดงละเอียด
            let medsHtml = '';
            
            // คำแนะนำยาจาก AI
            if (result.medicationAdvice && result.medicationAdvice.length > 0) {
                medsHtml += '<div class="mb-3"><h6 class="text-primary">💊 ยาที่แนะนำสำหรับอาการของคุณ:</h6></div>';
                result.medicationAdvice.forEach(advice => {
                    medsHtml += `
                        <div class="p-3 mb-3" style="background: #e8f5e9; border-radius: 10px; border-left: 4px solid #4caf50;">
                            <strong class="text-success">${advice.name}</strong>
                            <p class="mb-1 small"><strong>เหตุผล:</strong> ${advice.reason || ''}</p>
                            <p class="mb-1 small"><strong>วิธีใช้:</strong> ${advice.howToUse || ''}</p>
                            ${advice.precautions ? `<p class="mb-0 small text-warning"><strong>ข้อควรระวัง:</strong> ${advice.precautions}</p>` : ''}
                        </div>
                    `;
                });
            }
            
            // รายการยาจากร้าน
            if (result.medications && result.medications.length > 0) {
                medsHtml += '<div class="mt-3 mb-2"><h6>🏪 ยาที่มีในร้าน:</h6></div>';
                result.medications.forEach(med => {
                    medsHtml += `
                        <div class="p-3 mb-2 border rounded">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong>${med.name}</strong>
                                    ${med.genericName ? `<br><small class="text-muted">(${med.genericName})</small>` : ''}
                                </div>
                                <span class="badge bg-success fs-6">฿${med.price}</span>
                            </div>
                            ${med.indications ? `<p class="mb-1 mt-2 small"><strong>สรรพคุณ:</strong> ${med.indications}</p>` : ''}
                            ${med.dosage ? `<p class="mb-1 small"><strong>ขนาดยา:</strong> ${med.dosage}</p>` : ''}
                            ${med.usage ? `<p class="mb-1 small"><strong>วิธีใช้:</strong> ${med.usage}</p>` : ''}
                            ${med.warnings ? `<p class="mb-0 small text-warning"><strong>คำเตือน:</strong> ${med.warnings}</p>` : ''}
                        </div>
                    `;
                });
            }
            
            document.getElementById('recommendedMeds').innerHTML = medsHtml || '<p class="text-muted">ไม่มียาแนะนำ</p>';
        }

        // Share Result
        function shareResult() {
            if (liff.isApiAvailable('shareTargetPicker') && assessmentResult) {
                liff.shareTargetPicker([{
                    type: 'text',
                    text: `🩺 ผลประเมินอาการเบื้องต้น\n\n` +
                          `อาการ: ${assessmentData.symptoms.join(', ')}\n` +
                          `ระดับความเสี่ยง: ${assessmentResult.riskLevel === 'high' ? '🔴 สูง' : assessmentResult.riskLevel === 'medium' ? '🟡 ปานกลาง' : '🟢 ต่ำ'}\n\n` +
                          `${assessmentResult.recommendation || ''}`
                }]);
            }
        }

        // New Assessment
        function newAssessment() {
            document.getElementById('assessmentForm').style.display = 'block';
            document.getElementById('resultSection').style.display = 'none';
            // Reset form
            document.querySelectorAll('.symptom-btn').forEach(btn => btn.classList.remove('active'));
        }

        // Consult Pharmacist
        function consultPharmacist() {
            if (liff.isInClient()) {
                liff.sendMessages([{
                    type: 'text',
                    text: 'ปรึกษาเภสัชกร'
                }]).then(() => liff.closeWindow());
            }
        }
    </script>
</body>
</html>
