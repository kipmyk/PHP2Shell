# What My PHP Security Investigation Missed, and How I Will Improve It

**Author:** Mike Kipruto  
**Site:** [mike.co.ke](https://mike.co.ke)  
**Date:** October 26, 2026  
**Category:** Technology & Infrastructure / Software Engineering  

---

When investigating a security vulnerability, finding the relevant source changes is only the beginning.

I recently investigated reported PHP security issues affecting PHP 8.4.26 and examined the changes between the affected and patched source versions (CVE-2026-109618 and CVE-2026-109619).

I built an isolated Docker environment, tested multipart requests and session upload-progress behavior, and compared the relevant source code.

My initial tests returned successful HTTP responses. I did not reproduce the reported memory corruption or remote code execution.

Then I examined a published proof of concept demonstrating a complete exploit chain against the PHP challenge environment.

That changed how I assessed my progress.

---

## What I established

My investigation established a useful starting point:

- I examined the relevant PHP source changes in `main/rfc1867.c` and `ext/session/session.c`.
- I created a controlled dual-target environment for comparing affected (PHP 8.4.26) and patched (PHP 8.4.27) versions.
- I tested multipart request handling and session upload-progress behavior.
- I documented the fact that my initial tests did not reproduce the reported failure.

These are useful research steps. They are not evidence that I reproduced the vulnerability.

That distinction matters.

---

## What I missed

### 1. My tests did not establish that I had reached the vulnerable condition

A request returning HTTP 200 does not prove that the underlying code behaved safely. It only tells me that the request received a successful HTTP response.

Testing a related feature does not establish that I triggered the specific failure described in a vulnerability report.

I needed stronger evidence that my test exercised the relevant failure condition.

### 2. I needed to prioritize the upstream regression test

Source comparison can show where the implementation changed and help explain why.

However, the upstream regression test is an important next step because it provides a concrete test case designed to detect the affected behavior.

I should have made reproducing that test against both versions a central milestone earlier in the investigation.

The expected results must be verified rather than assumed. A meaningful comparison establishes whether the affected version exhibits the reported behavior and whether the patched version prevents it.

### 3. I needed to account for environment differences earlier

My initial environment used Debian-based PHP-FPM containers behind Nginx FastCGI.

The published PoC describes an Alpine-based PHP CLI server (`php -S`) running with multiple workers (`PHP_CLI_SERVER_WORKERS=4`).

These are not equivalent environments:

1. **SAPI Differences:** PHP-FPM recycles worker contexts and request globals differently than the multi-worker CLI development server.
2. **Buffering vs Pipelining:** FastCGI proxying buffers and serializes requests, whereas the exploit relies on raw HTTP/1.1 pipelining down a single TCP connection to race worker reuse.
3. **Memory Allocator Layout:** Alpine Linux uses musl libc, which arranges heap chunks and symbol page offsets differently than Debian's glibc.

Environment differences do not automatically explain a failed reproduction, but they must be documented and considered before drawing conclusions.

### 4. I needed to separate source analysis from exploit reproduction

I initially treated progress in understanding the source changes and testing related functionality as progress toward reproducing the vulnerability.

Those are connected activities, but they are different milestones.

A source-level explanation can be correct without reproducing the failure. A regression test can confirm that a fix prevents a particular failure without demonstrating a complete exploit. And a claimed end-to-end exploit needs its own reproducible evidence.

My reporting needs to reflect those distinctions.

---

## How I will approach future investigations

I am refining my workflow into separate stages:

**Stage 1: Establish the affected versions**  
Identify the advisory, affected version range, patched versions, upstream changes, and relevant regression tests.

**Stage 2: Reproduce the reported behavior**  
Start with the smallest relevant test case in an isolated environment. Record the exact build configuration and establish what happens on the affected and patched versions.

**Stage 3: Investigate discrepancies**  
If the test does not reproduce the expected behavior, examine the test assumptions, environment differences, execution path, and available diagnostic evidence before expanding the investigation.

**Stage 4: Evaluate exploitability separately**  
A confirmed regression does not automatically establish remote code execution. Any further claims require independent evidence appropriate to the claim, collected in a controlled environment.

**Stage 5: Publish reproducible findings**  
Document the environment, commands, expected results, actual results, logs, limitations, and unresolved questions. Clearly distinguish what I observed from what another researcher reported.

---

## The lesson

The main lesson is not that the initial investigation was wasted. It is that I need a stricter standard for deciding when a security finding has been reproduced.

Source analysis tells me where to look.

Regression tests help establish whether the relevant behavior is present.

Controlled reproduction provides evidence about what actually happens.

Exploitability requires further evidence.

I will use those distinctions to guide the next phase of this investigation and future security research.

---

**Research status:** Source changes examined. Initial multipart and session upload-progress tests completed. Reported memory corruption and remote code execution not reproduced in my Debian FPM environment. All research code and telemetry are open on GitHub: [github.com/kipmyk/PHP2Shell](https://github.com/kipmyk/PHP2Shell).
