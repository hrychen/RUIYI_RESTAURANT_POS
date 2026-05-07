/**
 * pos-table-resolver.js
 * Global table identifier resolver
 * 2026-05-07 v5: exact display names like "MESA 1" must beat canonical "Mesa X"
 *
 * Strategy order rationale:
 * - "Mesa X" with exact casing is the internal format, X is ALWAYS gtn
 * - "MESA X" can be a real category display name and must resolve through mappings
 * - "餐桌 X" / "Table X" are UI labels and can also be custom names → mapping first
 * - display_name match handles custom names ("T10", "6.5", "o1", "10")
 * - Pure integer fallback for server responses (gtn)
 *
 * Depends on: window.tableCategoryMappings (injected by PHP)
 */

window.resolveGlobalTableNumber = function(tableIdentifier) {
  if (!tableIdentifier && tableIdentifier !== 0) return '';
  var input = String(tableIdentifier).trim();
  if (!input) return '';
  var normalizeTableKey = function(value) {
    return String(value || '').trim().replace(/\s+/g, '').toLowerCase();
  };

  var mappings = window.tableCategoryMappings;
  if (!mappings) {
    return input;
  }
  var allM = Array.isArray(mappings) ? mappings : Object.values(mappings);
  if (allM.length === 0) {
    return input;
  }

  var inputLower = input.toLowerCase().replace(/\s+/g, ' ');
  var inputKey = normalizeTableKey(input);

  // Strategy 1: "Mesa X" canonical format.
  // Case-sensitive on purpose: "MESA X" may be a category display name.
  var canonicalMesaNumberMatch = input.match(/^Mesa\s*(\d+)$/);
  if (canonicalMesaNumberMatch) {
    return canonicalMesaNumberMatch[1];
  }

  // Strategy 2: Exact display/category match for UI labels.
  // A subsite can have a category literally named "MESA", producing display names
  // such as "MESA 1". Those are NOT internal "Mesa 1" canonical keys.
  var byDisplay = allM.find(function(m) {
    var dn = (m.category_display_name || m.display_name || '').trim();
    return dn && (dn === input || dn.toLowerCase() === inputLower || normalizeTableKey(dn) === inputKey);
  });
  if (byDisplay) return String(byDisplay.global_table_number);

  // Strategy 3: Match "category_name + local_number" format ("TERRAZA 1", "MESA 2")
  var byCategory = allM.find(function(m) {
    var catName = (m.category_name || '').trim();
    var localNum = m.local_table_number || m.table_number_in_category;
    if (!catName || !localNum) return false;
    var generated = (catName + ' ' + localNum).toLowerCase().replace(/\s+/g, ' ');
    return generated === inputLower || normalizeTableKey(generated) === inputKey;
  });
  if (byCategory) return String(byCategory.global_table_number);

  // Strategy 4: Non-integer "Mesa X" compatibility path.
  var mesaMatch = input.match(/^Mesa\s*(.+)$/);
  if (mesaMatch) {
    var mesaVal = mesaMatch[1].trim();
    // If X is pure integer → it IS the gtn, return directly
    if (/^\d+$/.test(mesaVal)) {
      return mesaVal;
    }
    // X is non-integer (e.g. "Mesa 9.5") → check display_name mapping
    var byDisplayInner = allM.find(function(m) {
      var dn = (m.category_display_name || '').trim();
      return dn === mesaVal;
    });
    if (byDisplayInner) return String(byDisplayInner.global_table_number);
  }

  // Strategy 5: Localized generic label fallback.
  // These are not canonical because users can rename a table to "餐桌 3"/"Table 3".
  // Only use the number if no exact mapping matched above.
  var localizedGenericMatch = input.match(/^(?:餐桌|Table)\s*(\d+)$/i);
  if (localizedGenericMatch) {
    return localizedGenericMatch[1];
  }

  // Strategy 6: Pure integer → match as global_table_number
  // This handles server responses where update.number is gtn
  if (/^\d+$/.test(input)) {
    return input;
  }

  // Strategy 7: Final fallback — return as-is
  return input;
};
