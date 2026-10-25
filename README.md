# PHP2Shell Vulnerability Research Lab (PHP 8.4.26 vs PHP 8.4.27)

An isolated, dual-target local research lab for analyzing the reported PHP2Shell vulnerabilities (CVE-2026-109618 and CVE-2026-109619). It enables side-by-side comparative analysis of **PHP 8.4.26** (the pre-patch baseline under investigation) versus **PHP 8.4.27** (the upstream security patch) behind **Nginx FastCGI**.

![Audit Dashboard](docs/screenshots/audit_dashboard.png)

---

## Research Scope

* **CVE-2026-109618 (`GHSA-w8hw-w9fx-j79w`):** Unsafe memory cleanup and allocator state handling during early request parsing and `zend_bailout()` aborts.
* **CVE-2026-109619 (`GHSA-6wmf-4rv5-p3rp`):** Multipart `session.upload_progress` tracking running before `PHP_RINIT`, leading to uninitialized module globals across requests on persistent PHP-FPM workers.
* **Investigation Status:** **Source changes verified; exploitability not reproduced**. Full audit and source diffs are documented in [docs/AUDIT_REPORT.md](docs/AUDIT_REPORT.md).

## Disclaimer & Terms of Use

> [!WARNING]
> **STRICTLY FOR TESTING AND EDUCATIONAL PURPOSES**
> 
> * **Educational Purpose Only:** This documentation, research lab, and analysis are provided strictly for educational purposes, defensive engineering, and understanding PHP runtime security.
> * **Isolated Local Testing:** All experiments should be conducted in an isolated local environment using systems and resources the researcher is authorized to test.
> * **Limitation of Liability:** The author and contributors are not responsible for any misuse, unauthorized access, operational disruption, damage, or legal consequences resulting from the application or adaptation of information contained in this repository.
> * **Ethical & Authorized Scope:** All experimentation was performed solely within an isolated local Docker testbed. Do not deploy, scan, or execute tests against systems, networks, or infrastructure without explicit, authorized permission.

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
│   ├── AUDIT_REPORT.md             # Complete source diff and test report
│   └── screenshots/                # Visual audit evidence
└── exploit_lab/                    # Controlled comparative test scripts
```

---

## Disclaimer & Legal Terms

1. **Educational & Defensive Purpose:** This repository is maintained strictly for educational research, threat modeling, and defensive engineering. It provides an isolated lab to study reported PHP engine memory behaviors and upstream patch mechanics. All experiments should be conducted in an isolated local environment using systems and resources the researcher is authorized to test.
2. **No Warranty & Limitation of Liability:** The materials and scripts are provided "as is", without warranty of any kind. Under no circumstances shall the author, contributors, or copyright holders be liable for any claim, damages, data loss, or other liability arising from the use, execution, or modification of this software.
3. **Authorized Testing Only:** Never test, scan, or execute exploit payloads against third-party systems, networks, or production servers without prior explicit written permission from the authorized owner.

