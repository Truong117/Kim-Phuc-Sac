<?php

namespace App\Http\Requests\Reports;

final class CareReportRules
{
    public static function workItems(string $prefix): array
    {
        $work = "$prefix.*";
        $customer = "$work.customers.*";

        return [
            $prefix => ['sometimes', 'array', 'list', 'min:1', 'max:20'],
            $work => ['array:client_key,services,customers,result,evidence_links,attachment_ids'],
            "$work.client_key" => ['required', 'uuid'],
            "$work.result" => ['required', 'string', 'max:1000'],
            "$work.services" => ['required', 'array', 'list', 'min:1', 'max:10'],
            "$work.services.*" => ['array:id,name'],
            "$work.services.*.id" => ['sometimes', 'nullable', 'integer', 'min:1'],
            "$work.services.*.name" => ['required', 'string', 'max:200'],
            "$work.customers" => ['required', 'array', 'list', 'min:1', 'max:20'],
            $customer => ['array:client_key,customer_id,new_customer,name,note,legacy_output_id,contact_update'],
            "$customer.client_key" => ['required', 'uuid'],
            "$customer.customer_id" => ['sometimes', 'nullable', 'integer', 'min:1'],
            "$customer.name" => ['sometimes', 'string', 'max:200'],
            "$customer.note" => ['sometimes', 'nullable', 'string', 'max:300'],
            "$customer.legacy_output_id" => ['sometimes', 'nullable', 'integer', 'min:1'],
            "$customer.new_customer" => ['sometimes', 'nullable', 'array:request_key,name,channel,contact'],
            "$customer.new_customer.request_key" => ["required_with:$customer.new_customer", 'uuid'],
            "$customer.new_customer.name" => ["required_with:$customer.new_customer", 'string', 'max:200'],
            "$customer.new_customer.channel" => ["required_with:$customer.new_customer", 'string', 'max:100'],
            "$customer.new_customer.contact" => ['sometimes', 'nullable', 'string', 'max:2048'],
            "$customer.contact_update" => ['sometimes', 'nullable', 'array:channel,value'],
            "$customer.contact_update.channel" => ["required_with:$customer.contact_update", 'string', 'max:100'],
            "$customer.contact_update.value" => ["required_with:$customer.contact_update", 'string', 'max:2048'],
            "$work.evidence_links" => ['sometimes', 'array', 'list', 'max:3'],
            "$work.evidence_links.*" => ['required', 'string', 'max:2048', 'url:http,https'],
            "$work.attachment_ids" => ['sometimes', 'array', 'list', 'max:3'],
            "$work.attachment_ids.*" => ['required', 'integer', 'min:1'],
        ];
    }
}
