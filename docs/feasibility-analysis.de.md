# Machbarkeitsanalyse: „Später senden“ für Roundcube 1.6 auf Plesk

> Erstellt am 21.10.2026 mit OpenAI Codex auf Basis der erfassten Serverumgebung (Plesk Obsidian 18.0.x, Debian, Roundcube 1.6.19, Postfix 3.5, Dovecot 2.4). Es ist eine Planungsgrundlage. Die anschließende Vorprüfung auf dem Zielserver hat den Versandweg über Plesks `sendmail`-Wrapper (mit DKIM-Signatur) und die Sent-Ablage per `doveadm` bestätigt. Die Details werden nicht veröffentlicht.

**Ja, das ist technisch machbar – ohne gespeicherte Nutzerpasswörter und ohne Web-Worker.** Für eure Umgebung empfehle ich eine eigene SQL-Auftragsverwaltung, dauerhafte MIME-Speicherung und einen CLI-Worker. Postfix übernimmt den Versand; ein eng begrenzter Dovecot-Adapter erledigt anschließend „Gesendet“ und Antwort-/Weiterleitungsmarkierungen.

**Die wichtigste offene Frage ist Plesks Outgoing Mail Control:** `sendmail -f` oder unauthentifiziertes SMTP an localhost sind hinsichtlich Mailbox-, Domain- und Subscription-Limits nicht automatisch gleichwertig mit normalem SMTP-AUTH. Diese Anforderung muss vor der eigentlichen Entwicklung auf eurem Server nachgewiesen werden.

Die folgenden Roundcube-Aussagen beziehen sich auf den Upstream-Quellcode von **1.6.19**. Abweichungen im Plesk-Paket und die tatsächlich aktive Serverkonfiguration sind **(prüfen)**. Es wurden keine Änderungen oder Versandtests auf eurem Server durchgeführt.

**Für ein brauchbares MVP würde ich 12–20 Personentage ansetzen, für eine betrieblich ausgereifte Version 30–45 PT insgesamt.**

| Umfang | Eigene Aufwandsschätzung |
|---|---:|
| Technische Vorprüfung: Versandweg, Plesk-Limits, DKIM, Dovecot-Rechte | 2–3 PT |
| MVP: planen, ansehen, abbrechen, Zeitpunkt und Inhalt ändern, Anhänge, HTML, „Gesendet“, grundlegende Fehlerbehandlung | 12–20 PT einschließlich Vorprüfung |
| Ausgereifte Version: Wiederherstellung nach Abstürzen, Monitoring, Rechtehärtung, bessere Bedienung, Migrationen, Update- und Wiederherstellungstests | 30–45 PT insgesamt |

Vorausgesetzt sind ein erfahrener Entwickler und eine Testumgebung. Eine zusätzliche, bislang nicht nachgewiesene Plesk-Authentifizierungsintegration wäre gesonderter Aufwand. Wenige Nutzer vereinfachen Skalierung und Betrieb; die schwierigen Fehlerfälle bleiben trotzdem bestehen.

**Roundcube bietet die erforderlichen Erweiterungspunkte, aber das Abfangen des Sendens muss seinen normalen Erfolgspfad vermeiden.**

| Hook/API in Roundcube 1.6.19 | Bedeutung für das Plugin |
|---|---|
| `message_ready` | Die Nachricht ist als `Mail_mime` aufgebaut. Der Aufrufer übernimmt `message`; `abort` und `result` verhindern hier den Versand nicht. |
| `message_before_send` | In `rcube::deliver_message()`, vor der Übermittlung. Hier lässt sich die vollständig vorbereitete Nachricht übernehmen. `abort` beeinflusst die Rückgabe des Versandaufrufs. |
| `message_sent` | Nach erfolgreicher SMTP-Übermittlung; für das Einplanen zu spät. |
| `message_saved` | Nach erfolgreichem IMAP-APPEND in `rcube_imap::save_message()`. Kein vorgeschaltetes Veto. |
| `message_draftsaved` | Separater Hook nach dem Speichern eines Entwurfs. |
| `rcmail_sendmail` | Existierende Klasse, unter anderem mit `headers_input()`, `create_message()`, `deliver_message()`, `save_message()` und `get_identity()`. |

Die maßgeblichen Stellen stehen in der [Send-Aktion](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/actions/mail/send.php), der [Transportimplementierung in `rcube`](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/lib/Roundcube/rcube.php#L1597-L1704), dem [IMAP-Speicherpfad](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/lib/Roundcube/rcube_imap.php#L2430-L2467) und der [Klasse `rcmail_sendmail`](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/include/rcmail_sendmail.php).

Die zentrale Falle ist **`abort=true` zusammen mit `result=true` in `message_before_send`**. Damit entfällt zwar SMTP, aber der aufrufende Code behandelt die Operation als erfolgreichen Versand: Antwort-/Weiterleitungsflags, Sent-Speicherung, Entwurfslöschung und Compose-Aufräumen können trotzdem folgen. Ein bloßes Abschalten der Sent-Kopie genügt deshalb nicht. [`rcmail_sendmail::deliver_message()`](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/include/rcmail_sendmail.php#L382-L441)

Ich würde folgenden Ablauf vorsehen:

1. Der Benutzer wählt „Später senden“. JavaScript ergänzt einen Planungsmarker, Termin und einen Idempotenzschlüssel.
2. Es wird weiterhin der normale Befehl `rcmail.command('send')` und damit `_action=send` verwendet. Uploadkontrolle, Editorübernahme und Compose-Validierung bleiben erhalten.
3. Serverseitig werden Sitzung, CSRF-Schutz, Compose-Zuordnung, Termin, Größenlimits und Absenderberechtigung geprüft. Autosave-Anfragen dürfen keine Planung auslösen.
4. Roundcube erzeugt die Nachricht einschließlich Anhängen, HTML und Inlinebildern.
5. Ein abschließender `message_before_send`-Handler übernimmt Nachricht, Envelope und Metadaten dauerhaft in die Queue.
6. Nach erfolgreichem Commit erzeugt das Plugin eine eigene Antwort, beispielsweise über `output->command('plugin.scheduled_send.created', …)` und anschließend `output->send('iframe')`. Letzteres beendet standardmäßig die Anfrage. Der normale Versand-Erfolgspfad wird dadurch nicht mehr erreicht.
7. Das Plugin erledigt seinen eigenen Compose-Abschluss und zeigt „Geplant für …“.

Die Ausgabefunktionen sind vorhanden; **dieser konkrete Ablauf ist ein begründeter Integrationsentwurf, der im Plesk-Paket praktisch getestet werden muss**. [Roundcube-Ausgabeimplementierung](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/include/rcmail_output_html.php)

Dabei müssen frühere Abbruchentscheidungen anderer Plugins respektiert werden. Alle erforderlichen Veränderungen durch andere `message_before_send`-Handler müssen vor dem abschließenden Queue-Handler erfolgen: **Hook-Reihenfolge (prüfen)**. Eine eigene Action, die einfach intern die Sendeklasse aufruft, wäre nicht gleichwertig; beispielsweise registriert Enigma bestimmte Verarbeitung nur für die tatsächliche Action `send`. [Plugin-Hook-Verarbeitung](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/lib/Roundcube/rcube_plugin_api.php#L466-L490), [Enigma-Initialisierung](https://github.com/roundcube/roundcubemail/blob/1.6.19/plugins/enigma/enigma.php#L52-L60)

**Die gespeicherte Nachricht muss nach Logout, Session-Ablauf und Löschen temporärer Uploads vollständig versendbar bleiben.** Deshalb werden materialisierte RFC-5322/MIME-Daten gespeichert, keine serialisierten PHP-Objekte oder temporären Attachment-Pfade. Roundcubes `create_message()` und `rcmail_action_mail_send::add_attachments()` übernehmen bereits HTML, Anhänge und CID-Verweise; diese Verarbeitung sollte weiterverwendet werden. [MIME-Erstellung](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/include/rcmail_sendmail.php#L280-L343), [Attachment-Verarbeitung](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/actions/mail/send.php#L321-L380)

Zusätzlich zum MIME-Inhalt benötigt jeder Auftrag:

- Eigentümer, kanonische Mailbox und eine Kennung ihrer konkreten Kontoinstanz.
- Zugelassenen Envelope-Absender und vollständige Envelope-Empfängerliste einschließlich Cc/Bcc.
- Identität, Revision, Message-ID, Termin, Zeitzone und Status.
- Zielordner für „Gesendet“.
- Ursprungsnachrichten für Antworten/Weiterleitungen, mit Mailboxkennung, UID und UIDVALIDITY beziehungsweise geeigneten Dovecot-Kennungen.
- Eine dauerhafte Bearbeitungsvorlage und gegebenenfalls die bisherige Entwurfsreferenz.

**Bcc benötigt besondere Aufmerksamkeit:** `mailto` im Hook ist noch nicht zwingend die vollständige Empfängerliste. Bcc gehört in den Envelope, darf im Transport-MIME nicht offengelegt werden und soll in der privaten Sent-Kopie erhalten bleiben. [Roundcubes Empfänger- und Bcc-Verarbeitung](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/lib/Roundcube/rcube.php#L1621-L1701)

Den bisherigen Entwurf und temporäre Anhänge erst nach dauerhaftem Queue-Commit entfernen. Scheitert anschließend nur die Entwurfslöschung, darf daraus kein zweiter Auftrag entstehen. Antwortmarkierungen und Sent-Kopie werden erst nach bestätigter Postfix-Übernahme erzeugt.

Für Inhaltsänderungen reicht im MVP ein klarer Ablauf: **Auftrag atomar auf `editing` setzen → dauerhaften nativen Entwurf öffnen → ausdrücklich neu einplanen.** Der alte Auftrag läuft währenddessen nicht weiter. Die Bearbeitungsvorlage muss verhindern, dass Signaturen oder Footer doppelt angewandt werden. PGP, S/MIME und Mailvelope würde ich zunächst ausdrücklich ausschließen; deren sichere Bearbeitung benötigt zusätzliche Integration.

**Die Elastic-Oberfläche kann vollständig im Plugin bleiben.**

- PHP: `add_button(..., 'toolbar')`, `include_script()`, `include_stylesheet()` und `register_action()`.
- Der Elastic-Container heißt `toolbar`; `compose-toolbar` ist seine HTML-ID.
- Für zusätzliche Formularfelder ist `template_object_composeformhead` ein realer Hook.
- JavaScript: eigener Befehl über `register_command()`, anschließend Übergabe an `rcmail.command('send')`.
- Eigene Plugin-Actions für Liste, Vorschau, Abbrechen, Terminändern und Bearbeiten; jede Mutation mit Besitzprüfung, CSRF-Schutz und Versionskontrolle.

Die genaue Position direkt neben „Senden“ und ein mögliches Split-Button-Dropdown sind mit Elastic **(prüfen)**. Die unterstützten Erweiterungspunkte stehen im [Compose-Template](https://github.com/roundcube/roundcubemail/blob/1.6.19/skins/elastic/templates/compose.html#L77-L116) und in der [Plugin-API](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/lib/Roundcube/rcube_plugin.php#L262-L325).

Ein natives `datetime-local`-Feld oder ein lokal gebündelter Picker genügt. Dazu gehört eine sichtbare IANA-Zeitzone, etwa `Europe/Berlin`. Bei Roundcubes automatischer Zeitzoneneinstellung muss die Browserzone ausdrücklich aufgelöst werden. Gespeichert werden der bestätigte UTC-Zeitpunkt, Zone und ursprüngliche Eingabe. Nicht existierende Sommerzeit-Uhrzeiten werden abgelehnt; doppelte Uhrzeiten verlangen eine eindeutige Offsetauswahl.

**Von euren drei Architekturvarianten empfehle ich a), ergänzt um Dovecot für die nachgelagerten Postfachoperationen.**

| Variante | Vorteile | Nachteile / Bewertung |
|---|---|---|
| **a) Eigene Queue, CLI-Worker, lokaler Postfix** | Gute Kontrolle über Termine, Änderungen und Abbruch; keine Nutzerpasswörter; Nachricht wird erst bei Fälligkeit eingeliefert. | Eigener Zustandsautomat und Sent-Ablage nötig. Plesk-Zuordnung muss nachgewiesen werden. **Beste Grundlage.** |
| **b) Beim Planen in Postfix HOLD** | Einlieferung kann während der gültigen Roundcube-Sitzung authentifiziert erfolgen; der spätere Worker braucht kein Nutzerpasswort. | Privilegierte Freigabe, frühe Filterung/Signierung, Queue-Alter, schwierige Bearbeitung und Abgleich mit eigener DB. **Für dieses Vorhaben nicht meine Wahl.** |
| **c) IMAP „Geplant“ als Nachrichtenquelle** | Inhalt liegt im Nutzerpostfach und ist in anderen Clients sichtbar. | Quota, Verschieben/Löschen, Inhaltsänderungen und Extraktion erschweren den Betrieb. Ein autorisiertes SQL-Auftragsregister bleibt erforderlich. **Machbar, aber nicht einfacher.** |

Für a) sind sowohl lokale Sendmail-Einlieferung als auch ein eng geschützter SMTP-Zugang denkbar. SMTP liefert differenzierte Annahmeantworten und üblicherweise eine Queue-ID. Sendmail benötigt keine SMTP-Anmeldung. Die Entscheidung sollte vom nachgewiesenen Plesk-Verhalten abhängen. Bei Sendmail: explizite Envelope-Empfänger, Argumentvektor ohne Shell und keine frei übergebenen Optionen; `-f` allein ist keine Absenderautorisierung.

Bei b) muss die Nachricht **schon bei der Annahme garantiert auf HOLD landen**. Erst normal einliefern und danach `postsuper -h` ausführen wäre ein Rennen gegen den sofortigen Versand. Während HOLD verfällt die Nachricht nicht; nach Freigabe wird ihr ursprüngliches Alter wieder relevant. Für lange gehaltene Nachrichten empfiehlt Postfix Requeue, aber `postsuper -r` durchläuft die SMTP-/Non-SMTP-Milter nicht erneut. Eine frische DKIM-Verarbeitung ist dadurch nicht garantiert. `postsuper` ist zudem ein privilegiertes Administrationswerkzeug. [Postfix-3.5-Manpage](https://manpages.debian.org/bullseye/postfix/postsuper.1.en.html)

Auch wenn die ursprüngliche Einlieferung authentifiziert erfolgt: Die spätere Freigabe ist kein neuer SMTP-AUTH-Vorgang. Der genaue Plesk-Zählungszeitpunkt wäre **(prüfen)**; Limits „zum Versandzeitpunkt“ lassen sich daraus nicht einfach ableiten. Änderungen sollten Ersatznachrichten erzeugen, niemals interne Postfix-Queue-Dateien bearbeiten.

Bei c) darf ein hochgeladener Header wie `X-Send-At` keinen Auftrag erzeugen. Nur eine authentifizierte Pluginaktion autorisiert den Versand; die DB bindet diesen Auftrag an Inhalt, Eigentümer und Nachrichtenkennung. `doveadm search` liefert Mailbox-GUID und UID. `fetch text` enthält Header und Body, aber die Standardausgabe besitzt zusätzliche Formatierung. **`doveadm fetch … | sendmail …` ist kein verlässlicher MIME-Transport.** Die bytegenaue Extraktion gegen eure 2.4.5 ist **(prüfen)**. [Dovecot Search](https://doc.dovecot.org/2.4.2/core/man/doveadm-search.1.html), [Fetch](https://doc.dovecot.org/2.4.3/core/man/doveadm-fetch.1.html), [Ausgabeformate](https://doc.dovecot.org/2.4.1/core/man/doveadm.1.html)

**„Abbrechen = in einem beliebigen IMAP-Client löschen“ lässt sich nicht uneingeschränkt garantieren.** Zwischen letzter Existenzprüfung und Postfix-Einlieferung bleibt ein Rennen. Ein verlässlich bestätigtes Abbrechen benötigt den atomaren Statuswechsel im Plugin. Nach Beginn der Übergabe muss die Oberfläche anzeigen, dass ein Rückruf nicht mehr sicher möglich ist.

Für die Sent-Ablage genügt ein wesentlich kleinerer Dovecot-Adapter: `doveadm save -u USER -m MAILBOX` speichert ohne Nutzerpasswort über administrative Berechtigungen. Quota gilt weiterhin. Der Ordner muss aus Benutzerpräferenz beziehungsweise SPECIAL-USE bestimmt werden; `Sent` ist nicht universell richtig. Anschließend können Flags separat gesetzt werden: `\Answered` für Antworten und das von Roundcube/Dovecot verwendete Weiterleitungskeyword, typischerweise `$Forwarded`. Ein Systemflag `\Forwarded` sollte nicht erfunden werden. [Doveadm Save](https://doc.dovecot.org/2.4.1/core/man/doveadm-save.1.html), [Flags](https://doc.dovecot.org/2.4.1/core/man/doveadm-flags.1.html)

**Plesks native Ausgangslimits sind der mögliche Showstopper.** Plesk dokumentiert unterschiedliche Zählpfade für authentifizierten Mailversand und lokale Skripte beziehungsweise Unix-Benutzer. Bei entsprechender Einstellung dürfen Sendmail-Nachrichten trotz überschrittener Mailbox- oder Domainlimits weiterlaufen. Ein eigener Dienstbenutzer ist außerdem nicht automatisch einer Mailbox oder Subscription zugeordnet. [Plesk: Funktionsweise der Limits](https://docs.plesk.com/en-US/obsidian/administrator-guide/mail/protection-from-outbound-spam/how-limits-work.73369/), [Serverweite Einstellungen](https://docs.plesk.com/en-US/obsidian/administrator-guide/mail/configuring-serverwide-mail-settings.59430/)

Daraus folgen vier Grenzen:

- `sendmail -f nutzer@domain` authentifiziert diesen Nutzer nicht.
- SMTP an localhost ohne AUTH erzeugt ebenfalls keine Mailboxidentität.
- Ein gemeinsames SMTP-Dienstkonto zählt zunächst als Dienstkonto.
- Dovecots administrative Postfachzugriffe erzeugen keine SMTP-SASL-Identität.

Eine unterstützte delegierte Authentifizierung könnte untersucht werden, ist durch `passdb plesk` und `userdb sql` aber nicht bereits gegeben **(prüfen)**. Eigene Plugin-Limits ersetzen keine gemeinsamen nativen Plesk-Zähler für normalen und geplanten Versand. Localhost pauschal freizuschalten wäre ebenfalls falsch; Plesk dokumentiert Ausnahmen von der Ausgangskontrolle für freigegebene Absender. [Plesk: Outbound-Spam-Schutz](https://docs.plesk.com/en-US/obsidian/administrator-guide/mail/protection-from-outbound-spam.71349/)

**Eine Roundcube-Identität allein verhindert kein Spoofing.** Standardmäßig sind Identitäten umfangreich editierbar; auch eine syntaktisch gültige benutzerdefinierte From-Adresse kann akzeptiert werden. Beim Einplanen und erneut vor Versand müssen sichtbarer Absender und Envelope-Absender gegen eine administrative Mailbox-/Aliaszuordnung geprüft werden. `identities_level` ist ergänzende Härtung, kein Berechtigungsnachweis. [Roundcube-Defaults](https://github.com/roundcube/roundcubemail/blob/1.6.19/config/defaults.inc.php#L680-L686), [Header-Verarbeitung](https://github.com/roundcube/roundcubemail/blob/1.6.19/program/include/rcmail_sendmail.php#L122-L150)

Versionsrelevant: `local_login_sender_maps` wurde erst mit Postfix 3.6 eingeführt und steht in eurem 3.5.25 nicht als Standardabsicherung zur Verfügung. Der Helper muss seine Berechtigungen selbst eng kontrollieren. Auch ein Loopback-Port ist für andere lokale Prozesse erreichbar. [Postfix-3.6-Ankündigung](https://www.postfix.org/announcements/postfix-3.6.0.html)

**DKIM ist bei lokaler Einlieferung auf eurem Versionsstand grundsätzlich vorgesehen, muss aber am tatsächlichen Versandweg geprüft werden.** Plesk dokumentiert seit 18.0.79 DKIM für `/usr/sbin/sendmail`. Daher sind Wrapper, Plesk-Mailhandler und Milter gemeinsam zu betrachten. Ein leeres `non_smtpd_milters` beweist allein keine fehlende Signierung; eine blinde Änderung dieses Parameters wäre ebenso unbegründet. [Plesk-Changelog](https://docs.plesk.com/release-notes/obsidian/change-log/), [Postfix-Milter](https://www.postfix.org/MILTER_README.html)

SPF hängt von der tatsächlich extern verwendeten Versand-IP und Envelope-Domain ab; DKIM von Signaturdomain, Schlüssel und Verarbeitungspfad. Beides ersetzt keine Prüfung, ob der Nutzer diese Absenderadresse verwenden darf. [SPF-Spezifikation](https://www.rfc-editor.org/rfc/rfc7208.html)

Die Vorprüfung braucht deshalb kontrollierte Vergleichsmails über normalen Roundcube-SMTP-Versand und den geplanten Workerpfad. Zu vergleichen sind:

- Mailbox-, Domain- und Subscription-Zähler einschließlich tatsächlicher Ablehnung bei überschrittenen Limits.
- Externe Versand-IP, Envelope-Absender, DKIM-`d=`/Selector sowie SPF/DMARC.
- Ablehnung fremder Mailboxen und unzulässiger Aliase.
- Kontosperre, Aliasentzug und volle Sent-Quota.

**Plesk bietet einen vorgesehenen Ort für lokale Roundcube-Konfiguration: `config.local.php`.** Seit 18.0.65 dokumentiert Plesk diese Datei für Anpassungen; die aktuelle Pluginanleitung verwendet `/usr/share/psa-roundcube/config/config.local.php`. Eure aktive `config.inc.php` muss sie tatsächlich laden **(prüfen)**. Die `.dpkg-dist` ist Anlass zum Vergleich, kein Beweis eines funktionierenden Includes. [Plesk: Roundcube-Plugins aktivieren](https://support.plesk.com/hc/en-us/articles/24152701483799-How-to-enable-Roundcube-plugins-in-Plesk-for-Linux)

Die Pluginliste dort unter Erhalt der Plesk-Standardplugins ergänzen. Core-Dateien, mitgelieferte Templates und `.dpkg-dist` bleiben unangetastet. Ein zusätzliches Pluginverzeichnis ist der vorgesehene Installationsweg; eine Garantie für jede Neuinstallation oder Reparatur ergibt sich daraus nicht. Deshalb: eigenes Repository, reproduzierbares Deployment, Backup und kurzer Funktionstest nach Updates.

Der Worker läuft als eigener Unix-Benutzer, beispielsweise `rc-scheduler`, außerhalb des Webroots. Er erhält nur Rechte auf seine Queue und eigene DB-Tabellen. Ein kleiner rootverwalteter Helper darf erforderliche privilegierte Operationen für bekannte Aufträge ausführen. **Kein dauerhaft als root laufender PHP-Worker, kein `sudo doveadm *`, kein allgemeiner administrativer Socket für PHP-FPM.** Konkrete Dovecot-UID/GID und Socketrechte sind **(prüfen)**.

**Für die Speicherung sind SQL-Metadaten verbindlich; MIME kann je nach Größenlimit in der DB oder einem privaten Spool liegen.**

| Speicherung | Bewertung |
|---|---|
| SQL-Metadaten plus MIME-BLOB | Für wenige Nutzer vertretbar; Auftrag und Inhalt lassen sich gemeinsam transaktional speichern. Größere DB, Backups und Paketgrößen beachten. |
| SQL-Metadaten plus privater Dateispool | Gut für größere Anhänge; benötigt temporäre Dateien, dauerhafte Speicherung, atomare Veröffentlichung und Bereinigung verwaister Dateien. |
| IMAP als alleinige Queue | Wegen veränderlicher Benutzerinhalte und fehlender gemeinsamer Transaktion nicht empfohlen. |

Ein nur für den Worker zugänglicher Spool braucht einen ausdrücklich begrenzten Einlieferungsweg vom Webprozess. Eine pauschal gemeinsam beschreibbare Ablage wäre keine ausreichende Trennung. Für das kleine MVP kann die DB-Variante daher die einfachere Lösung sein.

**Doppelversand lässt sich stark begrenzen, aber über DB, SMTP und IMAP nicht bedingungslos ausschließen.** Ein sinnvoller Zustandsautomat ist:

```text
preparing → pending → submitting → accepted → sent-copy-pending → done
                ↘ editing / cancelled
                          ↘ retry-wait / failed / uncertain
```

Atomare bedingte SQL-Updates übernehmen genau eine Revision. Dazu kommen Worker-Lease und auf diesem einzelnen Server ein Prozesslock. `SKIP LOCKED` würde ich erst nach Feststellung der MySQL-/MariaDB-Version voraussetzen.

Nach bestätigter Postfix-Annahme darf ein Sent- oder Flag-Fehler **niemals einen erneuten Versand auslösen**. „Gesendet“ bedeutet dabei, wie bei normalem SMTP-Versand, vom MTA angenommen; die endgültige Empfängerzustellung kann später scheitern.

Der kritische Fall ist ein Absturz nach möglicher Annahme, aber vor Speicherung der Bestätigung. Solche Aufträge werden `uncertain`; sie dürfen nicht einfach nach Ablauf eines Lease erneut gesendet werden. Queue, Logs und Message-ID helfen beim Abgleich, garantieren aber keine Deduplizierung. Diese Unsicherheit ist bereits im SMTP-Protokoll beschrieben. [RFC 5321](https://www.rfc-editor.org/rfc/rfc5321.html)

Weitere notwendige Regeln:

- Sichere Nichtannahme: begrenzte Wiederholung mit Backoff. Bei SMTP im MVP eine teilweise Empfängerablehnung vor `DATA` durch Abbruch der gesamten Transaktion behandeln.
- Sent-Kopie: eigene Wiederholungs- und Dublettenkontrolle; `doveadm save -g` allein garantiert keine Idempotenz.
- Fehleranzeige: dauerhafter Status in Roundcube, verständliche Fehlerkategorie und einmalige Benachrichtigung; Zustellfehler von Postfix gehen an den korrekten Envelope-Absender.
- Ausfälle: festlegen, wie verspätete Aufträge behandelt werden. Keine unkontrollierte Freigabe sehr alter Nachrichten nach längerem Stillstand.
- Restore: Worker zunächst stoppen und alte Queue-Daten mit Versandjournal abgleichen.
- Kontolöschung: offene Aufträge sperren und Inhalte gemäß Aufbewahrungsregel löschen. Eine später neu angelegte gleiche E-Mail-Adresse darf keine alten Aufträge übernehmen; Kontoinstanz/Generation **(prüfen)**.
- Passwortwechsel: beeinträchtigt den Scheduler nicht. Kontosperre oder entzogene Absenderberechtigung müssen den Versand dagegen verhindern.

Größenlimits müssen PHP-Uploadgrenzen, fertige MIME-Größe, Postfix-Limit und verfügbare Speicherung berücksichtigen; Base64 vergrößert Anhänge ungefähr um ein Drittel. Zusätzlich begrenzen: Empfängerzahl, offene Aufträge, Gesamtbytes und Planungshorizont. Disk-full darf niemals zum Verlust des ursprünglichen Entwurfs führen.

Mailinhalte, Bcc und Bearbeitungsvorlagen sind vertraulich. Kurze Aufbewahrung nach Abschluss, restriktive Rechte, gegebenenfalls Verschlüsselung mit getrenntem Schlüssel sowie eine Backup-Löschstrategie gehören dazu. Pluginlogs sollten Job-ID, Status, Zeit und Fehlercode enthalten, keine Inhalte oder Passwörter.

**Der MVP sollte den vollständigen gewünschten Ablauf unterstützen, einschließlich einfacher Inhaltsänderung durch erneutes Öffnen als Entwurf.** Wiederkehrende Termine, externe SMTP-Konten und Benutzer-Verschlüsselung bleiben Zusatzumfang.

Eine mögliche Dateistruktur wäre:

```text
/usr/share/psa-roundcube/plugins/scheduled_send/
  scheduled_send.php
  config.inc.php.dist
  lib/
    QueueRepository.php
    ScheduleService.php
    MimeCapture.php
    Authorization.php
  js/scheduled_send.js
  skins/elastic/
    scheduled_send.css
    templates/list.html
  localization/
    de_DE.inc
    en_US.inc
  SQL/
    mysql.initial.sql
    migrations/

/opt/roundcube-scheduled-send/
  bin/
    worker.php
    migrate.php
  lib/
    SubmissionAdapter.php
    DovecotAdapter.php
    AccountPolicy.php
  deploy/
    systemd/roundcube-scheduled-send.service
    systemd/roundcube-scheduled-send.timer
    cron.d/roundcube-scheduled-send
  tests/
  docs/operations.md

/usr/local/libexec/roundcube-scheduled-send-helper
/etc/roundcube-scheduled-send/
/var/lib/roundcube-scheduled-send/
```

Die Reihenfolge der Umsetzung:

1. **Plesk-Versandweg beweisen:** Absenderbindung, native Limits, DKIM und administrative Sent-Ablage.
2. Queue, Zustände, Idempotenz und CLI-Worker mit Testnachrichten.
3. Roundcube-Integration einschließlich vollständiger MIME-Erfassung.
4. Planungsliste, Vorschau, Storno und Bearbeitung.
5. Sent-Kopie, Flags, Fehlerbenachrichtigungen und Bereinigung.
6. Absturz-, Konkurrenz-, Quota-, Sommerzeit-, Kontolöschungs- und Updateprüfungen.

Ein systemd-Timer alle 30–60 Sekunden oder alternativ Cron jede Minute genügt. Es wird nur ein Scheduler aktiviert. Der Termin bezeichnet die angestrebte Übergabe an Postfix, nicht einen garantierten Empfangszeitpunkt.

**Meine Empfehlung für eure kleine Agentur: Eigenbau nur dann, wenn unbeaufsichtigter Versand aus Roundcube eine feste Anforderung ist.** Dann zuerst den 2–3-tägigen Plesk-Nachweis durchführen und bei Erfolg Variante a) umsetzen. Bleibt die identische native Limitzuordnung ungelöst und ist sie unverhandelbar, ist das ein Showstopper für den vorgeschlagenen lokalen Versandweg.

Für gelegentlichen Bedarf ist **Thunderbird Send Later wirtschaftlich sinnvoller**, sofern Rechner und Thunderbird zum Termin laufen können. Das ursprüngliche Profil muss verfügbar sein; andere Clients übernehmen die geplanten Nachrichten nicht automatisch. Verpasste Termine werden nach erneutem Start abgearbeitet. Die normalen Zugangsdaten verbleiben im Thunderbird-Profil. [Send-Later-Dokumentation](https://extended-thunder.github.io/send-later/)

**Gmail „Senden als“ würde ich für eure Plesk-Adressen aktuell nicht neu einführen:** Google kündigt die Entfernung dieser Funktion für Drittanbieteradressen im Januar 2027 an. Das ist ausdrücklich eine Änderung an „Send as“, zusätzlich zu den Änderungen bei POP/Gmailify. Google-gehostete Workspace-Identitäten sind ein anderer Fall; eine entsprechende Migration wäre ein eigenes Projekt. [Google: „Senden als“](https://support.google.com/mail/answer/22370?hl=en), [Google: angekündigte Änderungen](https://support.google.com/mail/answer/17101213)