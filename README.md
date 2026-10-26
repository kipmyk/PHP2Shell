# PHP2Shell Vulnerability Research Lab (PHP 8.4.26 vs PHP 8.4.27)

An isolated, dual-target local research lab for analyzing the reported PHP2Shell vulnerabilities (CVE-2026-109618 and CVE-2026-109619). It enables side-by-side comparative analysis of **PHP 8.4.26** (the pre-patch baseline under investigation) versus **PHP 8.4.27** (the upstream security patch) behind **Nginx FastCGI**.

![Audit Dashboard](docs/screenshots/audit_dashboard.png)

---

## Research Scope

* **CVE-2026-109618 (`GHSA-w8hw-w9fx-j79w`):** Unsafe memory cleanup and allocator state handling during early request parsing and `zend_bailout()` aborts.
* **CVE-2026-109619 (`GHSA-6wmf-4rv5-p3rp`):** Multipart `session.upload_progress` tracking running before `PHP_RINIT`, leading to uninitialized module globals across requests on persistent PHP-FPM workers.
* **Investigation Status:** **Source changes verified; exploitability not reproduced**. Technical findings and methodology write-up are published on [mike.co.ke](https://mike.co.ke).

## Disclaimer & Terms of Use

> [!WARNING]
> **STRICTLY FOR TESTING AND EDUCATIONAL PURPOSES**
> 
> * **Educational Purpose Only:** This documentation, research lab, and analysis are provided strictly for educational purposes, defensive engineering, and understanding PHP runtime security.
> * **Isolated Local Testing:** All experiments should be conducted in an isolated local environment using systems and resources the researcher is authorized to test.
> * **Limitation of Liability:** The author and contributors are not responsible for any misuse, unauthorized access, operational disruption, damage, or legal consequences resulting from the application or adaptation of information contained in this repository.
> * **Ethical & Authorized Scope:** All experimentation was performed solely within an isolated local Docker testbed. Do not deploy, scan, or execute tests against systems, networks, or infrastructure without explicit, authorized permission.

---

## Research Status and Limitations

### Current findings

This investigation examined the PHP source changes between the affected and patched versions, with particular attention to multipart form handling, session upload progress, and memory-management behavior.

Initial tests against PHP 8.4.26 and PHP 8.4.27 returned successful HTTP responses. These tests did not reproduce the reported memory corruption or remote code execution.

Source-level analysis and successful HTTP responses are not sufficient to establish exploitability or confirm that the vulnerability has been reproduced.

### What remains unverified

At this stage, I have not independently reproduced the complete reported exploit chain. In particular, the following remain unverified in my environment:

- The reported memory-corruption behavior.
- Session-cookie information disclosure.
- Recovery of useful memory or code addresses.
- The transition from memory corruption to remote code execution.

The published PoC describes a different environment from my initial test setup. Differences in the operating system, PHP build, SAPI, worker configuration, and memory layout may affect reproducibility.

### Limitations of the initial approach

My initial investigation focused on comparing source changes and testing multipart requests against the affected and patched versions. That established a starting point, but it did not establish that my test cases exercised the precise failure condition addressed by the upstream fix.

A successful HTTP response does not rule out a vulnerability. Equally, identifying relevant source changes does not prove that an exploit works.

### Next steps

The next phase will focus on reproducibility and stronger evidence:

1. Run the relevant upstream regression test against affected and patched builds in an isolated environment.
2. Record the PHP version, build configuration, SAPI, operating system, and worker model for each test.
3. Determine whether the test reproduces the expected failure on the affected version and passes on the patched version.
4. Investigate differences between the official regression test and my initial test cases.
5. Keep source analysis, regression-test results, memory-corruption reproduction, and exploitability as separate research milestones.
6. Document commands, logs, expected results, actual results, and limitations so that another researcher can independently evaluate the findings.

### Research integrity

I will distinguish between behavior confirmed by my own tests, findings reported by other researchers, and hypotheses that remain unverified.

Until the relevant behavior is reproduced, I will not describe this investigation as a successful reproduction of the vulnerability.

This repository is intended for defensive security research, controlled testing, and understanding the effectiveness of upstream fixes.

---

## Architecture Overview

```
[Attacker / Python Test Scripts]
               │
       ┌───────┴───────┐
       ▼ (Port 8888)   ▼ (Port 8889)
   ┌───────────────────────┐
   │      Nginx 1.25+      │ (Reverse proxy, FastCGI buffers)
   └───┬───────────────┬───┘
       │ :9000         │ :9000
   ┌───▼───────────┐ ┌─▼─────────────┐
   │   PHP-FPM     │ │    PHP-FPM    │
   │   (8.4.26)    │ │   (8.4.27)    │
   │  [VULNERABLE] │ │   [PATCHED]   │
   │ ├── print-flag│ │ ├── print-flag│
   └───────────────┘ └───────────────┘
```

---

## Quick Start

### 1. Launch the Lab
```bash
docker compose up -d
```

### 2. Verify Endpoints
* **Vulnerable Baseline (`PHP 8.4.26`):**
  * Challenge Web UI: [http://localhost:8888](http://localhost:8888)
  * Version Info: [http://localhost:8888/php-version.php](http://localhost:8888/php-version.php)
  * Ping Endpoint: `curl -X POST -d 'message=ping' http://localhost:8888/ping.php`
* **Patched Comparison (`PHP 8.4.27`):**
  * Version Info: [http://localhost:8889/php-version.php](http://localhost:8889/php-version.php)
  * Ping Endpoint: `curl -X POST -d 'message=ping' http://localhost:8889/ping.php`
* **Local Audit Dashboard:**
  * [http://localhost:8888/dashboard.html](http://localhost:8888/dashboard.html)

---

## Research & Benchmark Scripts

Located in [`exploit_lab/`](exploit_lab/):

```bash
# 1. Multipart MIME Header Parsing Scaling (observes rfc1867.c filter differences)
python3 exploit_lab/test_multipart_headers.py

# 2. Sequential Upload Progress Session Updates (verifies pre-RINIT session creation)
python3 exploit_lab/compare_regression.py

# 3. Upstream phpt Regression Scenarios (large keys and multipart structures)
python3 exploit_lab/test_upload_progress_regression.py

# 4. Out-of-Memory Request Parsing Test (runs upstream CLI server test)
docker exec php2shell-fpm php /var/www/html/reproduce_oom_request_parsing.php
```

---

## Debugging with GDB

Both containers run with `SYS_PTRACE` and have `gdb`, `strace`, and `binutils` pre-installed:

```bash
# Enter container
docker exec -it php2shell-fpm bash

# Attach GDB to worker process
gdb -p $(pgrep -o php-fpm)

# Key breakpoints
(gdb) b zend_error_noreturn
(gdb) b zval_ptr_dtor
(gdb) b php_session_destroy
```

---

## Repository Structure

```
├── docker-compose.yml              # Dual-target orchestration
├── Dockerfile.php                  # PHP 8.4.26-fpm with debug symbols & tools
├── Dockerfile.php-patched          # PHP 8.4.27-fpm comparison container
├── bin/
│   └── print-flag.c                # SUID binary returning mock challenge key
├── nginx/
│   └── nginx.conf                  # Nginx proxy mapping :8888 and :8889
├── php/
│   └── custom.ini                  # Session upload progress & memory limits
├── src/
│   ├── index.html                  # Challenge UI replica
│   ├── terms.html                  # Challenge terms of participation
│   ├── ping.php                    # Target ping endpoint
│   ├── php-version.php             # Target version endpoint
│   └── dashboard.html              # Investigation audit dashboard
├── docs/
│   └── screenshots/                # Visual audit evidence
└── exploit_lab/                    # Controlled comparative test scripts
```

---

## Disclaimer & Legal Terms

1. **Educational & Defensive Purpose:** This repository is maintained strictly for educational research, threat modeling, and defensive engineering. It provides an isolated lab to study reported PHP engine memory behaviors and upstream patch mechanics. All experiments should be conducted in an isolated local environment using systems and resources the researcher is authorized to test.
2. **No Warranty & Limitation of Liability:** The materials and scripts are provided "as is", without warranty of any kind. Under no circumstances shall the author, contributors, or copyright holders be liable for any claim, damages, data loss, or other liability arising from the use, execution, or modification of this software.
3. **Authorized Testing Only:** Never test, scan, or execute exploit payloads against third-party systems, networks, or production servers without prior explicit written permission from the authorized owner.

