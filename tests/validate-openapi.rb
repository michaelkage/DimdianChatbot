#!/usr/bin/env ruby
require "yaml"

def fail_contract(message)
  warn "OpenAPI contract validation failed: #{message}"
  exit 1
end

def resolve_pointer(document, reference)
  return nil unless reference.start_with?("#/")
  reference.split("/").drop(1).reduce(document) do |node, segment|
    key = segment.gsub("~1", "/").gsub("~0", "~")
    if node.is_a?(Hash)
      node[key]
    elsif node.is_a?(Array) && key.match?(/\A\d+\z/)
      node[Integer(key)]
    else
      nil
    end
  end
end

def walk_references(document, node, location = "$")
  case node
  when Hash
    if node.key?("$ref")
      reference = node["$ref"]
      fail_contract("#{location} has unsupported external reference #{reference}") unless reference.is_a?(String) && reference.start_with?("#/")
      fail_contract("#{location} points to unresolved reference #{reference}") if resolve_pointer(document, reference).nil?
    end
    node.each { |key, value| walk_references(document, value, "#{location}/#{key}") }
  when Array
    node.each_with_index { |value, index| walk_references(document, value, "#{location}/#{index}") }
  end
end

def validate_document(path, expected_paths, required_security)
  document = YAML.load_file(path)
  fail_contract("#{path} is not OpenAPI 3.1") unless document.is_a?(Hash) && document["openapi"] == "3.1.0"
  fail_contract("#{path} has no paths") unless document["paths"].is_a?(Hash)
  missing = expected_paths - document["paths"].keys
  fail_contract("#{path} is missing paths: #{missing.join(", ")}") unless missing.empty?

  schemes = document.dig("components", "securitySchemes") || {}
  required_security.each do |scheme|
    fail_contract("#{path} is missing security scheme #{scheme}") unless schemes.key?(scheme)
  end

  operation_ids = []
  document["paths"].each do |path_name, path_item|
    fail_contract("#{path} #{path_name} is not a path item") unless path_item.is_a?(Hash)
    path_item.each do |method, operation|
      next unless %w[get post put patch delete options head trace].include?(method.downcase)
      fail_contract("#{path} #{method.upcase} #{path_name} has no operationId") unless operation.is_a?(Hash) && operation["operationId"].is_a?(String)
      operation_ids << operation["operationId"]
      fail_contract("#{path} #{method.upcase} #{path_name} has no responses") unless operation["responses"].is_a?(Hash) && !operation["responses"].empty?
      if operation["requestBody"]
        content = operation.dig("requestBody", "content")
        fail_contract("#{path} #{method.upcase} #{path_name} requestBody has no content") unless content.is_a?(Hash) && !content.empty?
      end
    end
  end
  duplicates = operation_ids.group_by(&:itself).select { |_id, entries| entries.length > 1 }.keys
  fail_contract("#{path} has duplicate operationIds: #{duplicates.join(", ")}") unless duplicates.empty?
  walk_references(document, document)
  document
end

core_paths = %w[/session.php /messages.php /consent.php /health.php /webhook.php /meta-embedded-signup.php]
staff_paths = %w[/me.php /agents.php /inbox.php /messages.php /assignments.php /notes.php /replies.php /roles.php /history.php /audit.php]
core = validate_document("docs/openapi.yaml", core_paths, %w[GuestSessionToken MetaAdminKey])
staff = validate_document("docs/openapi-staff.yaml", staff_paths, %w[StaffAssertion])
fail_contract("staff API does not apply StaffAssertion globally") unless (staff["security"] || []).any? { |requirement| requirement.key?("StaffAssertion") }
puts "OpenAPI contracts valid: #{core_paths.length} core paths and #{staff_paths.length} staff paths; references and operation structure checked."
