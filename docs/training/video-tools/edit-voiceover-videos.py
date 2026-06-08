import argparse
import asyncio
import base64
from datetime import UTC, datetime
import json
import os
import shutil
import subprocess
import urllib.error
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PROJECT_ROOT = ROOT.parents[1]
VIDEOS = ROOT / "videos"
EDITED = VIDEOS / "edited"
TMP = EDITED / "_tmp"
FONT = "C\\:/Windows/Fonts/NotoSansThaiLooped-wdth-wght.ttf"

OPENAI_MODEL = os.getenv("OPENAI_TTS_MODEL", "gpt-4o-mini-tts")
OPENAI_VOICE = os.getenv("OPENAI_TTS_VOICE", "onyx")
OPENAI_SPEED = float(os.getenv("OPENAI_TTS_SPEED", "0.82"))
GOOGLE_LANGUAGE_CODE = os.getenv("GOOGLE_TTS_LANGUAGE_CODE", "th-TH")
GOOGLE_VOICE = os.getenv("GOOGLE_TTS_VOICE", "th-TH-Chirp3-HD-Algenib")
GOOGLE_AUDIO_ENCODING = os.getenv("GOOGLE_TTS_AUDIO_ENCODING", "MP3")
GOOGLE_TTS_ATEMPO = float(os.getenv("GOOGLE_TTS_ATEMPO", os.getenv("OPENAI_TTS_SPEED", "0.82")))
GOOGLE_TTS_ENDPOINT = os.getenv("GOOGLE_TTS_ENDPOINT", "https://texttospeech.googleapis.com/v1/text:synthesize")
GOOGLE_CLOUD_PLATFORM_SCOPE = "https://www.googleapis.com/auth/cloud-platform"
EDGE_VOICE = os.getenv("EDGE_TTS_VOICE", "th-TH-NiwatNeural")
VOICE_PROVIDER = os.getenv("CLINICYA_TTS_PROVIDER", "auto").lower()
VOICE_INSTRUCTIONS = (
    "Speak Thai like a calm male trainer teaching a pharmacy team. "
    "Use a warm, low, soft voice, slow pacing, clear pauses after each step, "
    "and practical instructional intonation. Do not sound rushed."
)

EDITED.mkdir(parents=True, exist_ok=True)
TMP.mkdir(parents=True, exist_ok=True)


JOBS = [
    {
        "id": "crm-overview",
        "source": VIDEOS / "crm-overview-click-recording.mp4",
        "title": "ภาพรวม CRM ของร้าน",
        "subtitle": "Dashboard, Inbox, ลูกค้า, Broadcast, Analytics และระบบสมาชิก",
        "narration": (
            "คลิปนี้เป็นภาพรวมของซีอาร์เอ็มร้านยา เริ่มจากแดชบอร์ดสำหรับดูภาพรวมงาน "
            "จากนั้นไปที่อินบ็อกซ์ซึ่งเป็นหน้าหลักสำหรับตอบแชท ดูข้อมูลลูกค้า และทำงานประจำวัน "
            "ต่อด้วยฐานลูกค้า แท็ก เซกเมนต์ บรอดแคสต์ และรายงานวิเคราะห์ "
            "สุดท้ายคือระบบสมาชิกและแต้มสะสม ซึ่งเชื่อมกับการดูแลลูกค้าซ้ำและการกลับมาซื้ออีกครั้ง"
        ),
    },
    {
        "id": "crm-membership-points",
        "source": VIDEOS / "crm-membership-points-click-recording.mp4",
        "title": "ระบบสมาชิกและแต้มสะสม",
        "subtitle": "สมาชิก รางวัลแลกแต้ม และการตั้งค่ากติกาแต้ม",
        "narration": (
            "ตอนนี้สาธิตหน้าระบบสมาชิกของร้าน เริ่มจากดูจำนวนสมาชิกและระดับสมาชิก "
            "จากนั้นเปิดแท็บรางวัลแลกแต้มเพื่อดูของรางวัล แต้มที่ใช้ และสถานะของรางวัล "
            "แล้วไปที่ตั้งค่าแต้มเพื่ออธิบายกติกาการคำนวณแต้มของร้าน "
            "จุดสำคัญคือทีมสามารถดูสมาชิก ตั้งค่ารางวัล และเชื่อมต่อกับการให้แต้มจากหน้าแชทได้ใน workflow เดียว"
        ),
    },
    {
        "id": "crm-inbox-add-points",
        "source": VIDEOS / "crm-inbox-add-points-click-recording.mp4",
        "title": "เพิ่มแต้มให้ลูกค้าทันทีจาก Inbox",
        "subtitle": "เปิดแชทลูกค้า ระบุ 1 แต้ม แล้วบันทึกเข้าบัญชีลูกค้าจริง",
        "narration": (
            "คลิปนี้สาธิตการเพิ่มแต้มให้ลูกค้าจากหน้าอินบ็อกซ์จริง "
            "เริ่มจากเปิดแชทลูกค้า แล้วกดปุ่มให้แต้มในหน้าซีอาร์เอ็ม "
            "ในตัวอย่างนี้ใส่หนึ่งแต้ม เลือกวิธีชำระเงินเป็นเงินสด แล้วกดให้ทันที "
            "เมื่อสำเร็จ ระบบจะแสดงแต้มที่เพิ่ม ยอดแต้มคงเหลือใหม่ บันทึกประวัติแต้ม และส่งใบรับแต้มให้ลูกค้าทางไลน์"
        ),
    },
    {
        "id": "crm-inbox-points-qr",
        "source": VIDEOS / "crm-inbox-points-qr-click-recording.mp4",
        "title": "สร้าง QR รับแต้มจาก Inbox",
        "subtitle": "สร้าง QR แบบใช้ครั้งเดียวให้ลูกค้าสแกนรับแต้มเอง",
        "narration": (
            "คลิปนี้สาธิตการสร้างคิวอาร์รับแต้มจากหน้าอินบ็อกซ์ "
            "หลังเปิดหน้าต่างให้แต้ม ให้ระบุจำนวนแต้มหนึ่งแต้ม แล้วเลือกสร้างคิวอาร์แทน "
            "ระบบจะสร้างเลขรายการ คิวอาร์โค้ด และเวลาหมดอายุสามสิบนาที "
            "ลูกค้าเปิดไลน์เพื่อสแกนคิวอาร์นี้และรับแต้มเข้าบัญชีของตัวเอง เหมาะกับการขายหน้าร้านที่ให้ลูกค้าสแกนรับแต้มเอง"
        ),
    },
    {
        "id": "crm-rewards-redemption",
        "source": VIDEOS / "crm-rewards-redemption-click-recording.mp4",
        "title": "รางวัลแลกแต้มและการติดตามการแลก",
        "subtitle": "ดูของรางวัล แต้มที่ใช้ และเชื่อมต่อกับการแลกของลูกค้า",
        "narration": (
            "ตอนนี้อธิบายการจัดการรางวัลแลกแต้มในฝั่งแอดมิน "
            "ทีมสามารถดูรายการรางวัล แต้มที่ใช้แลก สถานะ และภาพรวมการแลกของลูกค้า "
            "เมื่อลูกค้ามีแต้มจากการซื้อหรือจากคิวอาร์รับแต้ม ก็สามารถนำแต้มไปแลกรางวัลในฝั่งลูกค้าได้ "
            "ถ้าจะถ่ายขั้นตอนแลกจริง ต้องใช้เซสชันไลน์ของลูกค้าในมินิแอปเพื่อยืนยันการแลก"
        ),
    },
]


def run(args):
    subprocess.run(args, check=True)


def repo_path(file_path: Path) -> str:
    return str(file_path.relative_to(PROJECT_ROOT)).replace("\\", "/")


def ffprobe_duration(file_path: Path) -> float:
    out = subprocess.check_output(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            str(file_path),
        ],
        text=True,
    ).strip()
    return float(out)


def escape_drawtext(value: str) -> str:
    return (
        value.replace("\\", "\\\\")
        .replace(":", "\\:")
        .replace("'", "\\'")
        .replace("%", "\\%")
        .replace("\n", "\\n")
    )


def make_card(output: Path, title: str, subtitle: str, duration: float, theme: str):
    title_text = escape_drawtext(title)
    subtitle_text = escape_drawtext(subtitle)
    disclosure = escape_drawtext("เสียงบรรยายสร้างด้วย AI สำหรับคู่มือภายใน")
    bg = "0x06251B" if theme == "intro" else "0x0F172A"
    accent = "0x10B981"
    vf = (
        f"drawbox=x=0:y=0:w=iw:h=ih:color={bg}@1:t=fill,"
        f"drawbox=x=0:y=0:w=iw:h=10:color={accent}@1:t=fill,"
        f"drawtext=fontfile='{FONT}':text='{title_text}':fontcolor=white:fontsize=52:"
        "x=(w-text_w)/2:y=250,"
        f"drawtext=fontfile='{FONT}':text='{subtitle_text}':fontcolor=0xD1FAE5:fontsize=27:"
        "x=(w-text_w)/2:y=330,"
        "drawtext=fontfile='C\\:/Windows/Fonts/tahoma.ttf':text='REYA CRM Live Manual':"
        "fontcolor=0xA7F3D0:fontsize=22:x=48:y=48,"
        f"drawtext=fontfile='{FONT}':text='{disclosure}':fontcolor=0xCBD5E1:fontsize=20:"
        "x=(w-text_w)/2:y=h-72"
    )
    run(
        [
            "ffmpeg",
            "-y",
            "-f",
            "lavfi",
            "-i",
            f"color=c={bg}:s=1424x806:d={duration}",
            "-vf",
            vf,
            "-c:v",
            "libx264",
            "-pix_fmt",
            "yuv420p",
            str(output),
        ]
    )


def make_openai_voice(text: str, output: Path):
    api_key = os.getenv("OPENAI_API_KEY")
    if not api_key:
        raise RuntimeError("OPENAI_API_KEY is not set")

    payload = json.dumps(
        {
            "model": OPENAI_MODEL,
            "voice": OPENAI_VOICE,
            "input": text,
            "instructions": VOICE_INSTRUCTIONS,
            "response_format": "mp3",
            "speed": OPENAI_SPEED,
        },
        ensure_ascii=False,
    ).encode("utf-8")
    request = urllib.request.Request(
        "https://api.openai.com/v1/audio/speech",
        data=payload,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            output.write_bytes(response.read())
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"OpenAI TTS failed: HTTP {exc.code}: {detail}") from exc


def gcloud_command() -> str | None:
    return shutil.which("gcloud") or shutil.which("gcloud.cmd") or shutil.which("gcloud.ps1")


def google_auth_configured() -> bool:
    return bool(
        os.getenv("GOOGLE_TTS_ACCESS_TOKEN")
        or os.getenv("GOOGLE_APPLICATION_CREDENTIALS")
        or gcloud_command()
    )


def google_access_token() -> str:
    explicit_token = os.getenv("GOOGLE_TTS_ACCESS_TOKEN")
    if explicit_token:
        return explicit_token

    errors = []
    try:
        import google.auth
        import google.auth.transport.requests

        credentials, _ = google.auth.default(scopes=[GOOGLE_CLOUD_PLATFORM_SCOPE])
        if not credentials.valid:
            credentials.refresh(google.auth.transport.requests.Request())
        if credentials.token:
            return credentials.token
        errors.append("google-auth returned empty token")
    except Exception as exc:
        errors.append(f"google-auth: {exc}")

    command = gcloud_command()
    if command:
        try:
            token = subprocess.check_output(
                [command, "auth", "application-default", "print-access-token"],
                stderr=subprocess.DEVNULL,
                text=True,
            ).strip()
            if token:
                return token
            errors.append("gcloud returned empty token")
        except Exception as exc:
            errors.append(f"gcloud: {exc}")

    detail = "; ".join(errors) if errors else "no Google auth source found"
    raise RuntimeError(
        "Google TTS requires GOOGLE_TTS_ACCESS_TOKEN, "
        "GOOGLE_APPLICATION_CREDENTIALS with google-auth, or "
        "`gcloud auth application-default login`. "
        f"Details: {detail}"
    )


def apply_audio_tempo(file_path: Path, tempo: float):
    if abs(tempo - 1.0) < 0.001:
        return

    temp_output = file_path.with_name(f"{file_path.stem}-tempo{file_path.suffix}")
    run(
        [
            "ffmpeg",
            "-y",
            "-i",
            str(file_path),
            "-filter:a",
            f"atempo={tempo:.3f}",
            str(temp_output),
        ]
    )
    temp_output.replace(file_path)


def make_google_voice(text: str, output: Path):
    audio_config = {"audioEncoding": GOOGLE_AUDIO_ENCODING}

    payload = json.dumps(
        {
            "input": {"text": text},
            "voice": {
                "languageCode": GOOGLE_LANGUAGE_CODE,
                "name": GOOGLE_VOICE,
            },
            "audioConfig": audio_config,
        },
        ensure_ascii=False,
    ).encode("utf-8")
    request = urllib.request.Request(
        GOOGLE_TTS_ENDPOINT,
        data=payload,
        headers={
            "Authorization": f"Bearer {google_access_token()}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=120) as response:
            result = json.loads(response.read().decode("utf-8"))
            audio_content = result.get("audioContent")
            if not audio_content:
                raise RuntimeError("Google TTS response did not include audioContent")
            output.write_bytes(base64.b64decode(audio_content))
            apply_audio_tempo(output, GOOGLE_TTS_ATEMPO)
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Google TTS failed: HTTP {exc.code}: {detail}") from exc


async def make_edge_voice(text: str, output: Path):
    try:
        import edge_tts
    except ImportError as exc:
        raise RuntimeError("edge_tts is required for EDGE fallback") from exc

    communicate = edge_tts.Communicate(text, EDGE_VOICE, rate="-12%", volume="+8%")
    await communicate.save(str(output))


async def make_voice(text: str, output: Path):
    provider = VOICE_PROVIDER
    if provider not in {"auto", "google", "openai", "edge"}:
        raise RuntimeError("CLINICYA_TTS_PROVIDER must be auto, google, openai, or edge")

    if provider in {"auto", "google"} and google_auth_configured():
        try:
            make_google_voice(text, output)
            return "google"
        except RuntimeError as exc:
            if provider == "google":
                raise
            print(f"Google TTS unavailable; falling back: {exc}")

    if provider == "google":
        raise RuntimeError(
            "CLINICYA_TTS_PROVIDER=google requires GOOGLE_TTS_ACCESS_TOKEN, "
            "GOOGLE_APPLICATION_CREDENTIALS, or gcloud application-default auth"
        )

    if provider in {"auto", "openai"} and os.getenv("OPENAI_API_KEY"):
        make_openai_voice(text, output)
        return "openai"

    if provider == "openai":
        raise RuntimeError("CLINICYA_TTS_PROVIDER=openai requires OPENAI_API_KEY")

    await make_edge_voice(text, output)
    return "edge"


def concat_video(parts, output: Path):
    list_file = TMP / f"{output.stem}.txt"
    list_file.write_text(
        "\n".join(f"file '{p.as_posix()}'" for p in parts) + "\n",
        encoding="utf-8",
    )
    run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(list_file), "-c", "copy", str(output)])


def add_voice(video: Path, voice: Path, output: Path):
    duration = ffprobe_duration(video)
    run(
        [
            "ffmpeg",
            "-y",
            "-i",
            str(video),
            "-i",
            str(voice),
            "-filter_complex",
            "[1:a]volume=1.18,apad[a]",
            "-map",
            "0:v:0",
            "-map",
            "[a]",
            "-t",
            f"{duration:.3f}",
            "-c:v",
            "copy",
            "-c:a",
            "aac",
            "-b:a",
            "128k",
            "-movflags",
            "+faststart",
            str(output),
        ]
    )


def normalize_source(source: Path, output: Path):
    run(
        [
            "ffmpeg",
            "-y",
            "-i",
            str(source),
            "-vf",
            "scale=1424:806:force_original_aspect_ratio=decrease,pad=1424:806:(ow-iw)/2:(oh-ih)/2,fps=30,format=yuv420p",
            "-c:v",
            "libx264",
            "-preset",
            "medium",
            "-crf",
            "23",
            "-an",
            str(output),
        ]
    )


async def build_all(dry_run: bool = False):
    manifest = []
    missing_sources = []
    for job in JOBS:
        if not job["source"].exists():
            missing_sources.append(repo_path(job["source"]))
            continue

        base = job["id"]
        source = job["source"]
        intro = TMP / f"{base}-intro.mp4"
        body = TMP / f"{base}-body.mp4"
        outro = TMP / f"{base}-outro.mp4"
        merged_video = TMP / f"{base}-merged.mp4"
        voice = TMP / f"{base}-voice.mp3"
        output = EDITED / f"{base}-edited-voiceover.mp4"

        if dry_run:
            manifest.append(
                {
                    "id": base,
                    "source": repo_path(source),
                    "output": repo_path(output),
                    "title": job["title"],
                    "dry_run": True,
                }
            )
            continue

        make_card(intro, job["title"], job["subtitle"], 4.0, "intro")
        normalize_source(source, body)
        make_card(outro, "จบคลิป", "ใช้ภายในทีมที่มีสิทธิ์ดูข้อมูลจริงเท่านั้น", 3.0, "outro")
        concat_video([intro, body, outro], merged_video)
        provider = await make_voice(job["narration"], voice)
        add_voice(merged_video, voice, output)

        manifest.append(
            {
                "id": base,
                "source": repo_path(source),
                "output": repo_path(output),
                "title": job["title"],
                "voice_provider": provider,
                "openai_model": OPENAI_MODEL if provider == "openai" else None,
                "google_language_code": GOOGLE_LANGUAGE_CODE if provider == "google" else None,
                "google_audio_tempo": GOOGLE_TTS_ATEMPO if provider == "google" else None,
                "voice": {"openai": OPENAI_VOICE, "google": GOOGLE_VOICE}.get(provider, EDGE_VOICE),
                "ai_voice_disclosure": "เสียงบรรยายสร้างด้วย AI",
                "duration": round(ffprobe_duration(output), 3),
            }
        )
        print(f"built {output}")

    manifest_path = EDITED / ("edited-voiceover-manifest.dry-run.json" if dry_run else "edited-voiceover-manifest.json")
    manifest_path.write_text(
        json.dumps(
            {
                "generated_at": datetime.now(UTC).isoformat().replace("+00:00", "Z"),
                "voice_policy": "Auto provider order: Google Cloud TTS when configured, then OpenAI, then Edge fallback.",
                "ai_voice_disclosure": "เสียงบรรยายสร้างด้วย AI",
                "missing_sources": missing_sources,
                "videos": manifest,
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    if missing_sources:
        print("missing sources:")
        for source in missing_sources:
            print(f"- {source}")


def main():
    parser = argparse.ArgumentParser(description="Build edited CRM training videos with Thai AI voiceover.")
    parser.add_argument("--dry-run", action="store_true", help="Validate job configuration without generating media.")
    args = parser.parse_args()
    asyncio.run(build_all(dry_run=args.dry_run))


if __name__ == "__main__":
    main()
