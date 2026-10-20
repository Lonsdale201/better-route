"""Validate serialized OAS 3.1 documents and positive/negative wire samples.

Install test dependency: pip install -r tests/openapi/requirements.txt
Usage: php tests/openapi/export.php /tmp/openapi.json
       python tests/openapi/validate.py /tmp/openapi.json [native-samples.json ...]
No network reference resolution is enabled for response schema validation.
"""

import json
import sys
from pathlib import Path

from jsonschema import Draft202012Validator, FormatChecker
from openapi_spec_validator import OpenAPIV31SpecValidator
from referencing import Registry, Resource


def main():
    bundle = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    for name, document in bundle["documents"].items():
        OpenAPIV31SpecValidator(document).validate()
        print(f"PASS OpenAPI 3.1: {name}")
    root = {"$schema": "https://json-schema.org/draft/2020-12/schema", **bundle["documents"]["woo"]}
    registry = Registry().with_resource("urn:better-route:fixture", Resource.from_contents(root))
    samples = bundle["samples"]
    for filename in sys.argv[2:]:
        samples += json.loads(Path(filename).read_text(encoding="utf-8"))
    for index, sample in enumerate(samples):
        if "response" in sample:
            schema = {"$ref": f'urn:better-route:fixture#/components/responses/{sample["response"]}/content/application~1json/schema'}
        else:
            schema = {"$ref": f'urn:better-route:fixture#/components/schemas/{sample["schema"]}'}
        validator = Draft202012Validator(schema, registry=registry, format_checker=FormatChecker())
        errors = list(validator.iter_errors(sample["value"]))
        expected = sample.get("valid", True)
        if bool(errors) == expected:
            label = sample.get("schema", sample.get("response"))
            raise AssertionError(f"Sample {index} ({label}), expected valid={expected}: {errors}")
    print(f"PASS JSON Schema wire contracts: {len(samples)} positive/negative samples")


if __name__ == "__main__":
    main()
