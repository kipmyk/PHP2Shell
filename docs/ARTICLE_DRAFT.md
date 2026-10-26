# What My PHP Security Investigation Missed, and How I Am Refining My Method

**Author:** Mike Kipruto  
**Site:** [mike.co.ke](https://mike.co.ke)  
**Date:** October 26, 2026  
**Category:** Technology & Infrastructure  

When investigating a software vulnerability, finding the upstream source diff is only the first step. It is not proof of exploitability, and it is not a reproduction.

I recently investigated reported security issues affecting PHP 8.4.26 (CVE-2026-109618 and CVE-2026-109619), comparing the affected codebase against the PHP 8.4.27 release. I constructed an isolated dual-target Docker lab to test multipart form processing and session upload-progress tracking. 

My initial tests returned clean HTTP 200 responses. I did not observe memory corruption, process crashes, or remote code execution. 

Shortly after, I reviewed a published third-party proof of concept claiming an end-to-end exploit against a CTF challenge environment running PHP 8.4.26. That PoC described an environment with major architectural differences from my testbed: Alpine Linux with musl libc, the PHP built-in CLI development server (`php -S`), and multiple persistent workers (`PHP_CLI_SERVER_WORKERS=4`). 

The contrast between my test results and the published exploit chain highlighted a gap in my research process. Examining source patches and running functional tests confirmed that code changed, but it did not establish whether the underlying vulnerability was reachable, reproducible, or mitigated under specific operational conditions.

Here is what my initial investigation established, the methodological steps I missed, and how I am restructuring my vulnerability research workflow.

---

## What My Initial Investigation Established

My initial work in the research lab established three concrete data points:

1. **Source Code Modifications Were Verified:** Upstream changes in PHP 8.4.27 target two specific behaviors:
   * In `main/rfc1867.c`, a new MIME header filter (`php_mime_should_save_hdr`) discards non-essential multipart headers, and an explicit check (`if (ZEND_HAS_OOM())`) aborts processing when an out-of-memory condition occurs.
   * In `ext/session/session.c`, session tracking globals are initialized earlier via `php_rinit_session_globals()`, incomplete class handling is constrained during unserialization, and `_SESSION` references are destroyed on early multipart bails. Full source diffs are cataloged in [docs/AUDIT_REPORT.md](https://github.com/kipmyk/PHP2Shell/blob/main/docs/AUDIT_REPORT.md).
2. **Dual-Target Lab Orchestration:** I deployed side-by-side containers behind Nginx FastCGI via [docker-compose.yml](https://github.com/kipmyk/PHP2Shell/blob/main/docker-compose.yml): [Dockerfile.php](https://github.com/kipmyk/PHP2Shell/blob/main/Dockerfile.php) running PHP 8.4.26 and [Dockerfile.php-patched](https://github.com/kipmyk/PHP2Shell/blob/main/Dockerfile.php-patched) running PHP 8.4.27. Both environments shared identical runtime configurations (`session.upload_progress.enabled=On`, `session.upload_progress.cleanup=Off`, and a 128 MiB memory limit).
3. **MIME Header Timing Discrepancies:** Sending requests with 30,000 non-essential multipart headers via [exploit_lab/test_multipart_headers.py](https://github.com/kipmyk/PHP2Shell/blob/main/exploit_lab/test_multipart_headers.py) showed measurable differences in request processing duration (averaging ~25.5 ms on 8.4.26 versus ~12.9 ms on 8.4.27). This reflects the header discarding logic introduced in `rfc1867.c`. Sequential session writes tested via [exploit_lab/compare_regression.py](https://github.com/kipmyk/PHP2Shell/blob/main/exploit_lab/compare_regression.py) expanded session storage from 377 to 755 bytes cleanly on both versions.

These findings confirmed that PHP 8.4.27 altered request parsing and session lifecycle management. They did not prove memory corruption or demonstrate an exploitable state in PHP 8.4.26.

---

## What My Initial Methodology Missed

Reflecting on the initial test cycle revealed four critical blind spots:

### 1. Treating HTTP 200 Responses as Safety Evidence
A successful HTTP 200 response only confirms that the web server completed the request without an unhandled error. It does not prove that memory remained uncorrupted, nor does it prove that the vulnerable code path was triggered safely. In PHP, an out-of-memory bailout (`zend_bailout`) during request parsing can terminate execution before userland code runs, leaving the parent process or worker in an unverified state.

### 2. Delaying Upstream Regression Test Execution
The PHP source tree includes regression test cases (`.phpt` files) designed specifically to assert engine behavior under failure conditions. 

When I ran the official upstream test harness (`run-tests.php`):
* `tests/basic/oom_rfc1867_upload_progress.phpt` was skipped (`SKIP`) because the official Docker PHP-FPM images do not provide the `php-cgi` binary required by `oom_rfc1867_skipif.inc`.
* `sapi/cli/tests/oom_request_parsing_next_request.phpt` passed (`PASS`) on both PHP 8.4.26 and PHP 8.4.27.

Passing the second test only proved that URL-encoded POST parsing on the CLI server handled memory limits cleanly. It provided no evidence regarding multipart session upload progress. I should have identified harness dependencies earlier and compiled the required CGI SAPI instead of relying solely on black-box HTTP requests.

### 3. Conflating Source Analysis with Exploit Validation
A source code diff identifies what the upstream developers modified. It does not explain the preconditions necessary to trigger the bug in production. I treated understanding the diff as equivalent to understanding the vulnerability. They are distinct phases.

### 4. Overlooking Environment Sensitivity
My test environment used Debian GNU/Linux (glibc) with PHP-FPM behind an Nginx FastCGI reverse proxy. The third-party proof of concept targeted Alpine Linux (musl libc) using the built-in development server (`php -S`) with four worker processes.

When I implemented and tested the four-stage exploit flow in [exploit_lab/exploit_chain_poc.py](https://github.com/kipmyk/PHP2Shell/blob/main/exploit_lab/exploit_chain_poc.py) (OOM producer plus eight pipelined consumers), the heap leak stage failed to trigger on Debian PHP-FPM. While it is tempting to claim that FastCGI buffering or glibc allocator semantics prevented reproduction in my lab, doing so without direct memory profiling is speculation. What is factual is that SAPI architectures, worker process lifecycles, and standard library allocators differ fundamentally between those setups.

---

## Environmental Discrepancies: Hypotheses vs. Verified Facts

To maintain research integrity, differences between environments must be treated as hypotheses to test, not asserted as settled explanations:

* **Worker Lifecycle and State Retention:** The published PoC claims that an early request-parsing OOM leaves half-initialized session tracking data in a persistent worker, which is subsequently targeted by pipelined requests. In PHP-FPM, worker process recycling and FastCGI connection states follow different lifecycle rules than the CLI development server. Whether PHP-FPM completely isolates this state under all concurrency levels remains unverified in my lab.
* **Request Pipelining and Multiplexing:** The PoC relies on pipelining multiple requests down a single raw HTTP/1.1 socket connection. Nginx proxying to FastCGI buffers request streams and negotiates FastCGI records independently. This transport layer difference prevents identical socket-level delivery to the PHP worker.
* **Heap Layout and ASLR Offsets:** The PoC relies on hardcoded symbol offsets (`zval_ptr_dtor`) and relative distance calculations calibrated for an Alpine PHP 8.4.26 binary. Because musl libc and glibc manage chunk allocations and memory arenas differently, memory offsets derived on Alpine cannot be expected to function on Debian.

These points represent architectural differences that require isolated debugging, not excuses for an unverified test.

---

## A Structured Vulnerability Research Workflow

Moving forward, I am standardizing my security investigations into five discrete, verified stages:

```
[Stage 1: Scope]        Advisory verification, CVE tracking, and upstream commit diffing.
       │
[Stage 2: Harness]      Upstream .phpt regression test execution with required SAPIs compiled.
       │
[Stage 3: Isolation]    Controlled unit reproduction in a minimal, instrumentation-ready container.
       │
[Stage 4: Profiling]    GDB/ASan/Valgrind memory tracing to verify corruption independent of network payload.
       │
[Stage 5: Reporting]    Evidence-based documentation separating observed facts from unverified claims.
```

### Stage 1: Define Scope and Upstream Baseline
Identify the authoritative CVE record, affected version ranges, vendor advisories, and the exact commit hash of the patch. Isolate the diff to the smallest functional unit.

### Stage 2: Execute Official Upstream Regression Tests First
Before designing custom HTTP requests, run the vendor's test harness. If a test requires specific binaries (such as `php-cgi`), compile them. Establish whether the test fails on the unpatched build and passes on the patched build under controlled harness conditions.

### Stage 3: Isolate Runtime Variables
Document and fix all environmental variables before testing:
* Operating system and C standard library (Debian glibc vs. Alpine musl).
* SAPI implementation (CLI, CLI-server, FPM, Apache module).
* Process model (single process, prefork workers, thread pool).
* Allocator configurations and engine limits (`memory_limit`, `post_max_size`).

### Stage 4: Verify Memory State with Profilers, Not Just HTTP Codes
Do not evaluate memory safety through HTTP status codes. Attach debuggers (`gdb`), enable AddressSanitizer (ASan), or inspect core dumps. A vulnerability involving memory corruption must be validated by observing the allocator or memory access failure directly.

### Stage 5: Separate Observed Evidence from External Claims
When reviewing third-party PoCs or community reports:
* Attribute external claims explicitly to their original authors.
* Never report an external finding as reproduced until it runs successfully in an independently managed, verified environment.
* Clearly state what was tested, what passed, what failed, and what remains unverified.

---

## Research Status and Reproducibility

The current state of my research is documented transparently in the repository:

* **Source Review:** Verified. Patches in `main/rfc1867.c` and `ext/session/session.c` confirm upstream remediation for early request termination and upload progress session handling. See [docs/AUDIT_REPORT.md](https://github.com/kipmyk/PHP2Shell/blob/main/docs/AUDIT_REPORT.md).
* **Official Harness:** Incomplete. `oom_rfc1867_upload_progress.phpt` skipped due to missing CGI SAPI dependencies.
* **Controlled Benchmarks:** Documented. Multi-header scaling and upload progress tests recorded in [src/dashboard.html](https://github.com/kipmyk/PHP2Shell/blob/main/src/dashboard.html) and [docs/screenshots/audit_dashboard.png](https://github.com/kipmyk/PHP2Shell/blob/main/docs/screenshots/audit_dashboard.png).
* **Memory Corruption:** Not reproduced. Tests against PHP 8.4.26 in a Debian PHP-FPM environment did not demonstrate memory corruption or process aborts.
* **Third-Party PoC:** Unverified locally. The exploit chain tested in [exploit_lab/exploit_chain_poc.py](https://github.com/kipmyk/PHP2Shell/blob/main/exploit_lab/exploit_chain_poc.py) targeting Alpine CLI-server environments did not trigger heap leakage on Debian FPM.

### Repository Links:
* Main Research Repository: [github.com/kipmyk/PHP2Shell](https://github.com/kipmyk/PHP2Shell)
* Lab Orchestration: [docker-compose.yml](https://github.com/kipmyk/PHP2Shell/blob/main/docker-compose.yml)
* Vulnerable Target Container (8.4.26): [Dockerfile.php](https://github.com/kipmyk/PHP2Shell/blob/main/Dockerfile.php)
* Patched Target Container (8.4.27): [Dockerfile.php-patched](https://github.com/kipmyk/PHP2Shell/blob/main/Dockerfile.php-patched)
* Full Audit Report: [docs/AUDIT_REPORT.md](https://github.com/kipmyk/PHP2Shell/blob/main/docs/AUDIT_REPORT.md)
* Exploit Chain Script: [exploit_lab/exploit_chain_poc.py](https://github.com/kipmyk/PHP2Shell/blob/main/exploit_lab/exploit_chain_poc.py)
* Multipart Benchmark Script: [exploit_lab/test_multipart_headers.py](https://github.com/kipmyk/PHP2Shell/blob/main/exploit_lab/test_multipart_headers.py)
* Audit Dashboard Source: [src/dashboard.html](https://github.com/kipmyk/PHP2Shell/blob/main/src/dashboard.html)
* Audit Dashboard Evidence Capture: [docs/screenshots/audit_dashboard.png](https://github.com/kipmyk/PHP2Shell/blob/main/docs/screenshots/audit_dashboard.png)

---

## Conclusion

Vulnerability research demands intellectual honesty and strict experimental discipline. Finding where code changed in an open-source project is straightforward; proving how, where, and when that code can be triggered requires methodical verification.

My initial investigation established valid source differences, but it stopped short of proving or disproving the failure condition. Recognizing the boundary between observed evidence and assumption is what separates disciplined engineering from speculative commentary.

The discipline is the moat. That is the standard I will apply to every investigation that follows.
