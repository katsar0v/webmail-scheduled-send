"""End-to-end test of the scheduled_send plugin against the local dev stack.

Usage (from dev/, with the stack up and db-init done):
    python3 e2e.py [screenshot-dir]

Set BROWSER_PATH to use an installed Chromium-based browser instead of
Playwright's bundled one. Deletes all rows from scheduled_send_queue.
"""
import datetime as dt
import imaplib
import json
import smtplib
import subprocess
import os
import sys
import tempfile
import time
from email.message import EmailMessage
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = "http://localhost:8095/"
DEV = Path(__file__).resolve().parent
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(tempfile.mkdtemp(prefix="scheduled_send_e2e_"))
OUT.mkdir(parents=True, exist_ok=True)
TZ = "Europe/Berlin"
RUN = str(int(time.time()))  # makes subjects unique, so old mail in GreenMail does not matter


def sql(query):
    cmd = ["docker", "compose", "exec", "-T", "db", "sh", "-c",
           'mariadb -N -B -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"']
    return subprocess.run(cmd, cwd=DEV, input=query, capture_output=True, text=True, check=True).stdout.strip()


def worker():
    r = subprocess.run(["./dev.sh", "worker"], cwd=DEV, capture_output=True, text=True)
    return r.returncode, r.stdout + r.stderr


def imap_messages(user, folder):
    m = imaplib.IMAP4("127.0.0.1", 3143)
    m.login(user, "dev")
    typ, _ = m.select(folder)
    if typ != "OK":
        m.logout()
        return []
    _, data = m.search(None, "ALL")
    out = []
    for num in data[0].split():
        _, msg = m.fetch(num, "(FLAGS RFC822.HEADER)")
        out.append({"flags": msg[0][0].decode(), "headers": msg[0][1].decode(errors="replace")})
    m.logout()
    return out


def check(name, cond, detail=""):
    cond = bool(cond)
    print(("PASS " if cond else "FAIL ") + name + (f" -- {detail}" if detail and not cond else ""))
    return cond


def login(page):
    page.goto(BASE)
    page.fill("#rcmloginuser", "alice@example.test")
    page.fill("#rcmloginpwd", "dev")
    page.click("#rcmloginsubmit")
    page.wait_for_selector("#messagelist", timeout=20000)


def compose(page, to, subject, body, bcc=None, url=None):
    page.goto(url or BASE + "?_task=mail&_action=compose")
    page.wait_for_selector(".formbuttons button.scheduled-send", timeout=20000)
    page.evaluate("""([to, bcc]) => {
        $('#_to').val(to).change();
        if (bcc) { $('#_bcc').val(bcc).change(); }
    }""", [to, bcc])
    page.fill("#compose-subject", subject)
    page.evaluate("(b) => { $('#composebody').val(b); }", body)


def wait_scheduled(page):
    page.wait_for_function("() => /geplant|scheduled|планиран/i.test(document.querySelector('#messagestack').innerText)", timeout=20000)
    return page.inner_text("#messagestack")


def schedule(page, local_value):
    page.click(".formbuttons button.scheduled-send")
    page.wait_for_selector("#scheduled-send-at", timeout=10000)
    page.fill("#scheduled-send-at", local_value)
    page.click(".ui-dialog-buttonpane button.mainaction")
    wait_scheduled(page)


def local_in(minutes):
    from zoneinfo import ZoneInfo
    t = dt.datetime.now(ZoneInfo(TZ)) + dt.timedelta(minutes=minutes)
    return t.strftime("%Y-%m-%dT%H:%M")


results = []
sql("DELETE FROM scheduled_send_queue")

# A message in alice's inbox to reply to later
msg = EmailMessage()
msg["From"], msg["To"], msg["Subject"], msg["Message-ID"] = "dave@example.test", "alice@example.test", f"Original for reply {RUN}", f"<orig-{RUN}@example.test>"
msg.set_content("Please reply.")
with smtplib.SMTP("127.0.0.1", 3025) as s:
    s.send_message(msg)

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get("BROWSER_PATH") or None)
    ctx = browser.new_context(timezone_id=TZ, locale="de-DE", viewport={"width": 1400, "height": 900})
    page = ctx.new_page()
    errors = []
    page.on("pageerror", lambda e: errors.append(str(e)))
    login(page)

    # 1) Schedule a message with Bcc
    compose(page, "Bob <bob@example.test>", f"Geplanter Test 1 {RUN}", "Hallo Bob,\nzeitversetzt.", bcc="carol@example.test")
    page.screenshot(path=str(OUT / "01-compose.png"))
    page.click(".formbuttons button.scheduled-send")
    page.wait_for_selector("#scheduled-send-at")
    page.screenshot(path=str(OUT / "02-dialog.png"))
    page.fill("#scheduled-send-at", local_in(3))
    page.click(".ui-dialog-buttonpane button.mainaction")
    notice = wait_scheduled(page)
    page.screenshot(path=str(OUT / "03-after-schedule.png"))
    results.append(check("confirmation shown", any(w in notice.lower() for w in ("geplant", "scheduled", "планиран")), notice))

    row = sql("SELECT id, status, envelope_from, recipients, subject, LENGTH(body), bcc IS NOT NULL, timezone FROM scheduled_send_queue ORDER BY id DESC LIMIT 1")
    print("DB:", row)
    job1 = row.split("\t")[0] if row else None
    results.append(check("job stored as pending", row and "\tpending\t" in row, row))
    results.append(check("bcc in envelope", row and "carol@example.test" in row, row))

    # 2) Not due yet: worker sends nothing
    rc, out = worker()
    results.append(check("worker before due sends nothing", rc == 0 and not any(f"Geplanter Test 1 {RUN}" in m["headers"] for m in imap_messages("bob@example.test", "INBOX")), out))

    # 3) Settings list
    page.goto(BASE + "?_task=settings&_action=plugin.scheduled_send")
    page.wait_for_selector("#scheduled-send-list, .scheduled-send-empty", timeout=15000)
    page.screenshot(path=str(OUT / "04-settings-list.png"), full_page=True)
    results.append(check("list shows job", page.locator(f"#scheduled-send-list tr[data-id='{job1}']").count() == 1))

    # 4) Make it due and run the worker
    sql(f"UPDATE scheduled_send_queue SET send_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = {job1}")
    rc, out = worker()
    print("worker:", rc, out.strip())
    time.sleep(1)
    bob = [m for m in imap_messages("bob@example.test", "INBOX") if f"Geplanter Test 1 {RUN}" in m["headers"]]
    carol = [m for m in imap_messages("carol@example.test", "INBOX") if f"Geplanter Test 1 {RUN}" in m["headers"]]
    results.append(check("bob received", len(bob) == 1, str(len(bob))))
    results.append(check("carol (bcc) received", len(carol) == 1, str(len(carol))))
    results.append(check("no Bcc header at recipient", bob and "Bcc:" not in bob[0]["headers"]))
    results.append(check("Date header present", bob and "\nDate:" in "\n" + bob[0]["headers"]))
    sent = [m for m in imap_messages("alice@example.test", "Sent") if f"Geplanter Test 1 {RUN}" in m["headers"]]
    results.append(check("sent copy filed", len(sent) == 1, str(len(sent))))
    results.append(check("sent copy keeps Bcc", sent and "Bcc: carol@example.test" in sent[0]["headers"]))
    results.append(check("sent copy marked seen", sent and "\\Seen" in sent[0]["flags"], sent and sent[0]["flags"]))
    status = sql(f"SELECT status, IFNULL(last_error,''), LENGTH(body) FROM scheduled_send_queue WHERE id = {job1}")
    results.append(check("job done, body dropped", status.startswith("done\t\t0"), status))

    # 5) Reply flagged as answered after delivery
    page.goto(BASE + "?_task=mail&_mbox=INBOX")
    page.wait_for_selector("#messagelist tbody tr", timeout=20000)
    uid = page.evaluate("""(run) => { for (const [id, row] of Object.entries(rcmail.message_list.rows)) {
        if (row.obj && row.obj.textContent.includes('Original for reply ' + run)) return id; } return null; }""", RUN)
    compose(page, "dave@example.test", f"Re: Original for reply {RUN}", "Antwort.", url=BASE + f"?_task=mail&_action=compose&_reply_uid={uid}&_mbox=INBOX")
    schedule(page, local_in(5))
    job2 = sql("SELECT id FROM scheduled_send_queue ORDER BY id DESC LIMIT 1")
    flagrow = sql(f"SELECT flag_name, flag_message_id FROM scheduled_send_queue WHERE id = {job2}")
    results.append(check("reply target recorded", flagrow.startswith(f"answered\t<orig-{RUN}@example.test>"), flagrow))
    orig = [m for m in imap_messages("alice@example.test", "INBOX") if f"Original for reply {RUN}" in m["headers"]]
    results.append(check("original not answered before send", orig and "\\Answered" not in orig[0]["flags"], orig and orig[0]["flags"]))
    sql(f"UPDATE scheduled_send_queue SET send_at = UTC_TIMESTAMP() WHERE id = {job2}")
    worker()
    orig = [m for m in imap_messages("alice@example.test", "INBOX") if f"Original for reply {RUN}" in m["headers"]]
    results.append(check("original answered after send", orig and "\\Answered" in orig[0]["flags"], orig and orig[0]["flags"]))

    # 5b) Editing a scheduled reply keeps the reply context (X-Draft-Info)
    compose(page, "dave@example.test", f"Re: Original for reply {RUN} (2)", "Zweite Antwort.", url=BASE + f"?_task=mail&_action=compose&_reply_uid={uid}&_mbox=INBOX")
    schedule(page, local_in(60))
    job2b = sql("SELECT id FROM scheduled_send_queue ORDER BY id DESC LIMIT 1")
    page.goto(BASE + "?_task=settings&_action=plugin.scheduled_send")
    page.click(f"#scheduled-send-list a.restore[data-id='{job2b}']")
    page.wait_for_timeout(2500)
    m = imaplib.IMAP4("127.0.0.1", 3143)
    m.login("alice@example.test", "dev")
    m.select("Drafts")
    _, d = m.search(None, "SUBJECT", f'"Original for reply {RUN} (2)"')
    info = ""
    for num in d[0].split():
        _, r = m.fetch(num, "(BODY.PEEK[HEADER.FIELDS (X-DRAFT-INFO)])")
        info += r[0][1].decode()
    m.logout()
    results.append(check("restored reply keeps X-Draft-Info", "type=reply" in info, info.strip()))

    # 6) Edit = back to Drafts
    compose(page, "bob@example.test", f"Geplanter Test 3 {RUN} (bearbeiten)", "Entwurf zurück.")
    schedule(page, local_in(60))
    job3 = sql("SELECT id FROM scheduled_send_queue ORDER BY id DESC LIMIT 1")
    page.goto(BASE + "?_task=settings&_action=plugin.scheduled_send")
    page.click(f"#scheduled-send-list a.restore[data-id='{job3}']")
    page.wait_for_timeout(2500)
    page.screenshot(path=str(OUT / "05-after-restore.png"), full_page=True)
    drafts = [m for m in imap_messages("alice@example.test", "Drafts") if f"Geplanter Test 3 {RUN}" in m["headers"]]
    results.append(check("restored into Drafts", len(drafts) == 1, str(len(drafts))))
    results.append(check("job3 cancelled", sql(f"SELECT status FROM scheduled_send_queue WHERE id = {job3}") == "cancelled"))

    # 7) Cancel
    compose(page, "bob@example.test", f"Geplanter Test 4 {RUN} (abbrechen)", "Wird abgebrochen.")
    schedule(page, local_in(60))
    job4 = sql("SELECT id FROM scheduled_send_queue ORDER BY id DESC LIMIT 1")
    page.goto(BASE + "?_task=settings&_action=plugin.scheduled_send")
    page.click(f"#scheduled-send-list a.cancel[data-id='{job4}']")
    page.wait_for_selector(".ui-dialog-buttonpane button.mainaction")
    page.click(".ui-dialog-buttonpane button.mainaction")
    page.wait_for_timeout(2500)
    results.append(check("job4 cancelled", sql(f"SELECT status FROM scheduled_send_queue WHERE id = {job4}") == "cancelled"))
    sql(f"UPDATE scheduled_send_queue SET send_at = UTC_TIMESTAMP() WHERE id IN ({job3}, {job4})")
    worker()
    bob = [m for m in imap_messages("bob@example.test", "INBOX") if f"Geplanter Test 3 {RUN}" in m["headers"] or f"Geplanter Test 4 {RUN}" in m["headers"]]
    results.append(check("cancelled jobs never sent", len(bob) == 0, str(len(bob))))

    # 8) Past date is rejected
    compose(page, "bob@example.test", f"Vergangenheit {RUN}", "x")
    page.click(".formbuttons button.scheduled-send")
    page.wait_for_selector("#scheduled-send-at")
    page.evaluate("() => { $('#scheduled-send-at').attr('min', null); }")
    page.fill("#scheduled-send-at", local_in(-10))
    page.click(".ui-dialog-buttonpane button.mainaction")
    page.wait_for_timeout(1000)
    page.screenshot(path=str(OUT / "06-past-date.png"))
    results.append(check("past date blocked in UI", sql(f"SELECT COUNT(*) FROM scheduled_send_queue WHERE subject = 'Vergangenheit {RUN}'") == "0"))

    page.goto(BASE + "?_task=settings&_action=plugin.scheduled_send")
    page.wait_for_selector("#scheduled-send-list")
    page.screenshot(path=str(OUT / "07-final-list.png"), full_page=True)
    results.append(check("no JS errors", not errors, "; ".join(errors)))
    browser.close()

print(f"\n{sum(results)}/{len(results)} checks passed, screenshots in {OUT}")
sys.exit(0 if all(results) else 1)
