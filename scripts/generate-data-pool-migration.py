#!/usr/bin/env python3
"""Generate data-pool normalized tables migration from storage contract."""

from __future__ import annotations

import json
from pathlib import Path


def sql_col(c: dict) -> str:
    name = c["name"]
    null = "NULL" if c.get("nullable") else "NOT NULL"
    default = ""
    if c.get("default") is not None and c["type"] == "bigint":
        default = f" DEFAULT {int(c['default'])}"
    t = c["type"]
    if t == "bigint":
        return f"{name} bigint {null}{default}"
    if t == "integer":
        return f"{name} integer {null}"
    if t == "date":
        return f"{name} date {null}"
    if t == "timestamptz":
        return f"{name} timestamptz {null}"
    if t == "decimal":
        prec, scale = c.get("precision", [20, 6])
        return f"{name} numeric({prec},{scale}) {null}"
    if t == "char":
        return f"{name} char({c.get('length', 3)}) {null}"
    if t == "json":
        return f"{name} jsonb {null}"
    return f"{name} text {null}"


def schema_create(table: str, p: dict, nk: list[str]) -> str:
    body = [
        f"            Schema::create('{table}', function (Blueprint $table) {{",
        "                $table->id();",
    ]
    for c in p["columns"]:
        name = c["name"]
        t = c["type"]
        if name in ("digital_asset_id", "external_resource_id", "last_collection_run_id", "last_dataset_run_id"):
            line = f"$table->unsignedBigInteger('{name}')"
        elif t == "bigint":
            line = f"$table->bigInteger('{name}')"
        elif t == "integer":
            line = f"$table->integer('{name}')"
        elif t == "date":
            line = f"$table->date('{name}')"
        elif t == "timestamptz":
            line = f"$table->timestampTz('{name}')"
        elif t == "decimal":
            prec, scale = c.get("precision", [20, 6])
            line = f"$table->decimal('{name}', {prec}, {scale})"
        elif t == "char":
            line = f"$table->char('{name}', {c.get('length', 3)})"
        elif t == "json":
            line = f"$table->json('{name}')"
        else:
            line = f"$table->text('{name}')"
        if c.get("nullable"):
            line += "->nullable()"
        elif c.get("default") is not None and t == "bigint":
            line += f"->default({int(c['default'])})"
        body.append(f"                {line};")
    body.append("                $table->timestamps();")
    nk_list = "', '".join(nk)
    uniq = f"{table[:40]}_nk_unique"
    body.append(f"                $table->unique(['{nk_list}'], '{uniq}');")
    for idx in p.get("indexes") or []:
        cols = "', '".join(idx["columns"])
        body.append(f"                $table->index(['{cols}'], '{idx['name'][:55]}');")
    body.append("            });")
    return "\n".join(body)


def main() -> None:
    reg = json.loads(Path("docs/data-contracts/MOXDOP_DATA_POOL_STORAGE_V1.json").read_text())
    parts: list[str] = []
    parts.append(
        """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

/**
 * Prompt 10 normalized data-pool tables.
 * PostgreSQL: RANGE monthly partitions for high-volume daily facts.
 * SQLite/MySQL: equivalent non-partitioned tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
"""
    )

    for p in reg["physical_datasets"]:
        table = p["table"]
        partitioned = p["partition_strategy"] == "RANGE_MONTHLY"
        nk = list(p["natural_key"])
        if partitioned and "reporting_date" not in nk:
            nk.append("reporting_date")
        parts.append(f"\n        // {table} | {p['write_mode']} | {p['partition_strategy']}\n")
        create_fn = schema_create(table, p, nk)
        if partitioned:
            cols_sql = ", ".join(
                [
                    "id bigserial NOT NULL",
                    *[sql_col(c) for c in p["columns"]],
                    "created_at timestamptz NULL",
                    "updated_at timestamptz NULL",
                    "PRIMARY KEY (id, reporting_date)",
                    f"UNIQUE ({', '.join(nk)})",
                ]
            )
            parts.append("        if ($driver === 'pgsql') {\n")
            parts.append(
                f"            DB::statement('CREATE TABLE IF NOT EXISTS {table} ({cols_sql}) PARTITION BY RANGE (reporting_date)');\n"
            )
            for idx in p.get("indexes") or []:
                cols = ", ".join(idx["columns"])
                parts.append(
                    f"            DB::statement('CREATE INDEX IF NOT EXISTS {idx['name']} ON {table} ({cols})');\n"
                )
            parts.append("        } else {\n")
            parts.append(create_fn + "\n")
            parts.append("        }\n")
        else:
            parts.append(create_fn + "\n")

    parts.append("\n    }\n\n    public function down(): void\n    {\n")
    for p in reversed(reg["physical_datasets"]):
        parts.append(f"        Schema::dropIfExists('{p['table']}');\n")
    parts.append("    }\n};\n")

    path = Path("database/migrations/2026_08_13_160100_create_data_pool_normalized_tables.php")
    path.write_text("".join(parts))
    print(f"wrote {path} ({path.stat().st_size} bytes, {len(reg['physical_datasets'])} tables)")


if __name__ == "__main__":
    main()
