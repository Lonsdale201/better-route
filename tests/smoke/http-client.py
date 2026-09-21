"""Run against the temporary HTTP smoke plugin; never print the token."""

import concurrent.futures
import json
import pathlib
import sys
import urllib.error
import urllib.parse
import urllib.request

base, token_file = sys.argv[1:3]
target = urllib.parse.urlsplit(base)
if target.scheme != "https" or not target.hostname or target.username or target.password or target.query or target.fragment:
    raise ValueError("Use an HTTPS site URL without credentials, query or fragment.")
token = pathlib.Path(token_file).read_text().strip()
checks = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


opener = urllib.request.build_opener(NoRedirect())


def request(method, path, key=None, authenticated=True):
    headers = {"Cache-Control": "no-cache"}
    if authenticated:
        headers["X-Better-Route-Smoke"] = token
    if key:
        headers["Idempotency-Key"] = key
    req = urllib.request.Request(base.rstrip("/") + "/wp-json/" + path, method=method, headers=headers)
    try:
        response = opener.open(req, timeout=45)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, json.loads(response.read()), dict(response.headers)


def check(condition, label):
    global checks
    checks += 1
    if not condition:
        raise RuntimeError(label)


check(request("POST", "better-route-smoke/v1/setup", authenticated=False)[0] == 404, "token gate")
try:
    status, setup, _ = request("POST", "better-route-smoke/v1/setup")
    check(status == 200, f"setup HTTP {status}: {setup.get('code', setup.get('error', {}).get('code', 'unknown'))}")
    run = setup["run"]
    for method in ("GET", "POST"):
        for version, item_id in (("v1", 1), ("v2", 1), ("v1", 2), ("v1", 1)):
            status, body, _ = request(method, f"{run}/{version}/items/{item_id}?id=2", "shared")
            check(status == 200 and body == {"version": version, "id": item_id}, "HTTP route isolation")
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor:
        futures = [executor.submit(request, "POST", f"{run}/v1/race", "concurrent") for _ in range(2)]
        results = [future.result() for future in futures]
    check(all(result[0] in (201, 409) for result in results), "concurrent statuses")
    check(any(result[0] == 201 for result in results), "one write succeeds")
    status, body, _ = request("GET", "better-route-smoke/v1/count")
    check(status == 200 and body["writes"] == 1, "exactly one side effect")
    status, body, headers = request("POST", f"{run}/v1/race", "concurrent")
    check(status == 201 and any(k.lower() == "idempotency-replayed" and v == "true" for k, v in headers.items()), "HTTP replay")
    check(request("GET", "better-route-smoke/v1/count")[1]["writes"] == 1, "replay has no side effect")
    print(json.dumps({"assertions": checks, "version": setup["version"], "concurrent_statuses": [r[0] for r in results]}))
finally:
    status, body, _ = request("DELETE", "better-route-smoke/v1/cleanup")
    check(status == 200 and body.get("cleaned") is True, "HTTP fixture cleanup")
    print(json.dumps({"assertions": checks, "cleanup": "complete"}))
