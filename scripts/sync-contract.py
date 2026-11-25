"""Extract the project-key operation catalog from a reviewed developer OpenAPI file."""

import json
import sys
from pathlib import Path


def catalog(source):
    schemas = source["components"]["schemas"]

    def shape(schema):
        value = {}
        if "$ref" in schema:
            value.update(shape(schemas[schema["$ref"].split("/")[-1]]))
        for part in schema.get("allOf", []):
            if part.get("$ref") != "#/components/schemas/Object":
                value.update(shape(part))
        for key in ("type", "enum", "minimum", "maximum", "minLength", "maxLength",
                    "pattern", "required", "description", "format"):
            if key in schema:
                value[key] = schema[key]
        if "properties" in schema:
            value["properties"] = {key: shape(part) for key, part in schema["properties"].items()}
        if "oneOf" in schema:
            value["oneOf"] = [shape(part) for part in schema["oneOf"]]
        if "items" in schema:
            value["items"] = shape(schema["items"])
        return value

    operations = {}
    for path, methods in source["paths"].items():
        for method, operation in methods.items():
            if not any("api-key" in scheme for scheme in operation.get("security", [])):
                continue
            content = operation.get("requestBody", {}).get("content", {})
            body = shape(next(iter(content.values()))["schema"]) if content else {}
            params = [item for item in operation.get("parameters", []) if item["in"] == "query"]
            query = {"type": "object", "required": [p["name"] for p in params if p.get("required")],
                     "properties": {p["name"]: shape(p["schema"]) for p in params}}
            for param in params:
                field = query["properties"][param["name"]]
                if param["name"] in ("page", "limit") and "type" not in field:
                    field["type"] = "integer"
                if "description" in param:
                    field["description"] = param["description"]
            operations[operation["operationId"]] = {
                "method": method.upper(), "path": path.removeprefix("/v1"),
                "query": query, "body": body, "multipart": "multipart/form-data" in content,
            }
    return operations


if __name__ == "__main__":
    document = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8-sig"))
    output = Path(__file__).resolve().parents[1] / "includes/operations.json"
    output.write_text(json.dumps(catalog(document), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
