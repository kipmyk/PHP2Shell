# PHP2Shell (CVE-2026-109618 & CVE-2026-109619) Local Investigation Report

**Target Environment:** Isolated Docker Lab replicating [stealmybitcoin.com](https://stealmybitcoin.com)  
**Baseline Under Investigation:** `PHP 8.4.26` (Port 8888)  
**Patched Comparison Build:** `PHP 8.4.27` (Port 8889)  
**Upstream Source Audit:** `php/php-src` (Tags `php-8.4.26` and `php-8.4.27`)  
**Status / Classification:** **Source changes verified; exploitability not reproduced**

---

## 1. Executive Summary

This investigation evaluated the differences between **PHP 8.4.26** (the version advertised by [stealmybitcoin.com](https://stealmybitcoin.com/php-version.php)) and the patched **PHP 8.4.27** release in an isolated local lab.

* **Upstream Source Diffs:** Confirmed that upstream security patches for `GHSA-w8hw-w9fx-j79w` (`CVE-2026-109618`) and `GHSA-6wmf-4rv5-p3rp` (`CVE-2026-109619`) introduced multipart MIME header filtering, pre-RINIT session global resets, and restricted deserialization to incomplete classes.
* **Test Harness Execution:** Running the official upstream `run-tests.php` harness revealed that `tests/basic/oom_rfc1867_upload_progress.phpt` skips in standard container environments because it explicitly requires `php-cgi` (`TEST_PHP_CGI_EXECUTABLE`).
* **Controlled Request Behavior:** In local HTTP runs, both versions processed ordinary multipart and upload-progress requests returning `200 pong`. Timing differences were observed on high-header multipart requests, but no memory corruption, crash, or code execution was observed in either environment.

---

## 2. Visual Audit Dashboard

![PHP2Shell Test Results Audit Dashboard](screenshots/audit_dashboard.png)

![Local StealMyBitcoin Challenge UI Replica](screenshots/challenge_ui.png)

---

## 3. Upstream Source Diff Evidence

Review of the changes between `php-8.4.26` and `php-8.4.27` identifies the exact code modifications:

### A. Multipart Header Handling in `main/rfc1867.c`
In PHP 8.4.27, a new MIME header filter was added:
```diff
+static bool php_mime_should_save_hdr(const char *name)
+{
+	return !strcasecmp(name, "Content-Disposition")
+		|| !strcasecmp(name, "Content-Type");
+}
...
-	key = estrdup(line);
-	smart_string_appends(&buf_value, value);
+	/* Keep only headers we use, and ignore duplicates */
+	if (!php_mime_should_save_hdr(line) || php_mime_get_hdr_value(*header, line)) {
+		key = NULL;
+	} else {
+		key = estrdup(line);
+		do { value++; } while (isspace((unsigned char)*value));
+		smart_string_appends(&buf_value, value);
+	}
+	if (ZEND_HAS_OOM()) {
+		goto oom;
+	}
```

### B. Pre-RINIT Session Safety in `ext/session/session.c`
In PHP 8.4.27, session globals are reset upon entering the multipart handler:
```diff
 	switch(event) {
 		case MULTIPART_EVENT_START: {
 			multipart_event_start *data = (multipart_event_start *) event_data;
+
+			/* This runs before RINIT, so we need to clear these early */
+			php_rinit_session_globals();
+
 			progress = ecalloc(1, sizeof(php_session_rfc1867_progress));
```
And deserialization during upload-progress updates is restricted:
```diff
+static void php_session_unserialize_init(php_unserialize_data_t *var_hash)
+{
+	PHP_VAR_UNSERIALIZE_INIT(*var_hash);
+	if (PS(in_rfc1867_update)) {
+		php_var_unserialize_set_incomplete_classes_only(*var_hash, true);
+	}
+}
```

---

## 4. Official Upstream Regression Test Execution

Using the official PHP test harness `run-tests.php`:

### Test 1: `tests/basic/oom_rfc1867_upload_progress.phpt`
```bash
docker run --rm -v $(pwd)/php-src-audit:/src -w /src php:8.4.27-fpm \
  php run-tests.php -P tests/basic/oom_rfc1867_upload_progress.phpt
```
* **Result:** `SKIP`
* **Reported Reason:** `php-cgi not available`
* **Finding:** The test relies on `oom_rfc1867_skipif.inc`, which verifies `getenv('TEST_PHP_CGI_EXECUTABLE')`. The standard official PHP-FPM images supply the `cli` and `fpm` SAPIs, not `cgi`. Running this specific test requires compiling the CGI binary.

### Test 2: `sapi/cli/tests/oom_request_parsing_next_request.phpt`
```bash
docker run --rm -v $(pwd)/php-src-audit:/src -w /src php:8.4.27-fpm \
  php run-tests.php -P sapi/cli/tests/oom_request_parsing_next_request.phpt

docker run --rm -v $(pwd)/php-src-audit:/src -w /src php:8.4.26-fpm \
  php run-tests.php -P sapi/cli/tests/oom_request_parsing_next_request.phpt
```
* **Result:** `PASS` on both PHP 8.4.26 and PHP 8.4.27.
* **Finding:** Both versions pass this URL-encoded request-parsing test on the CLI server. Passing this test does not prove the vulnerability is absent or fixed in other request paths (such as multipart upload progress).

---

## 5. Controlled Local HTTP Benchmark Results

### A. Multipart Header Scaling
* **Script:** `python3 exploit_lab/test_multipart_headers.py`
* **Methodology:** Requests with 15,000 and 30,000 non-standard headers were sent over HTTP to `http://localhost:8888/ping.php` and `http://localhost:8889/ping.php`.
* **Observations:**
  * In the 15,000-header run: 8.4.26 took ~28.9 ms; 8.4.27 took ~10.6 ms.
  * In the 30,000-header run: 8.4.26 took ~25.5 ms; 8.4.27 took ~12.9 ms.
* **Evidence Limit:** The timing difference is consistent with the code difference in `rfc1867.c` (header filtering), but without direct allocator profiling, this observation alone does not quantify heap allocations or prove memory exhaustion.

### B. Upload Progress Session Persistence
* **Script:** `python3 exploit_lab/compare_regression.py`
* **Observations:** Both containers written serialized session files in `/tmp` (`sess_testbench*`) which expanded from 377 to 755 bytes across two sequential updates.
* **Evidence Limit:** Successful session writes and worker PID stability confirm that upload progress tracking functions in both builds; they do not demonstrate memory corruption or safety.

---

## 6. What the Results Prove vs. What Remains Unproven

| Area | Proven by Evidence | Not Proven / Unverified |
| :--- | :--- | :--- |
| **Upstream Code** | PHP 8.4.27 contains specific patches for MIME header filtering, pre-RINIT session resets, and incomplete-class restrictions. | Whether these specific changes completely mitigate all related bailout edge cases. |
| **Local Environment** | Both containers communicate, run the specified PHP versions, and handle multipart traffic. | Whether the local FPM/Nginx configuration matches the live challenge server's exact internals. |
| **Harness Requirements** | `oom_rfc1867_upload_progress.phpt` requires `php-cgi` and skips under standard FPM container builds. | How `oom_rfc1867_upload_progress.phpt` behaves when executed with a compiled CGI binary on 8.4.26. |
| **Exploitability** | Exploitability was not reproduced locally; requests returned valid HTTP 200 responses. | The exact real-world exploitability or lack thereof under crafted memory corruption chains. |

---

## 7. Next Most Informative Local Step

To execute the actual upstream regression test (`oom_rfc1867_upload_progress.phpt`) without skips:
1. Compile the `php-cgi` binary from the checkout in `php-src-audit` for both `php-8.4.26` and `php-8.4.27`.
2. Set `TEST_PHP_CGI_EXECUTABLE=/path/to/php-cgi` and execute `run-tests.php` to observe the official soft-limit memory assertions directly.
