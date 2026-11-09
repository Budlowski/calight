(() => {
  "use strict";

  const allowed = new Set(["open", "in_progress", "done"]);
  const normalized = (value) => (allowed.has(value) ? value : "open");

  function collect(nodes, result = []) {
    for (const node of Array.isArray(nodes) ? nodes : []) {
      if (node.type === "position") result.push(normalized(node.workStatus));
      else if (node.type === "title") collect(node.children, result);
    }
    return result;
  }

  function derived(nodes) {
    const statuses = collect(nodes);
    if (statuses.length === 0) return "neutral";
    if (statuses.every((status) => status === "open")) return "open";
    if (statuses.every((status) => status === "done")) return "done";
    return "in_progress";
  }

  function progress(nodes) {
    const statuses = collect(nodes);
    const done = statuses.filter((status) => status === "done").length;
    return {
      done,
      total: statuses.length,
      percent: statuses.length ? Math.round((done / statuses.length) * 100) : 0,
    };
  }

  function visible(node, filter) {
    if (!filter) return true;
    if (node.type === "position") return normalized(node.workStatus) === filter;
    if (node.type === "title")
      return (node.children || []).some((child) => visible(child, filter));
    return false;
  }

  globalThis.CalightOfferStatus = { normalized, derived, progress, visible };
})();
