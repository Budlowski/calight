(() => {
  "use strict";

  const copy = (value) => structuredClone(value);
  const equal = (left, right) => JSON.stringify(left) === JSON.stringify(right);

  class OfferHistory {
    constructor(limit = 10) {
      this.limit = limit;
      this.past = [];
      this.future = [];
      this.current = null;
      this.group = null;
    }

    reset(snapshot) {
      this.past = [];
      this.future = [];
      this.current = copy(snapshot);
      this.group = null;
    }

    record(snapshot, group = null) {
      const next = copy(snapshot);
      if (this.current === null) {
        this.reset(next);
        return false;
      }
      if (equal(this.current, next)) return false;
      if (group && group === this.group) {
        this.current = next;
        this.future = [];
        if (this.past.length && equal(this.past[this.past.length - 1], next))
          this.past.pop();
        return true;
      }
      this.past.push(this.current);
      if (this.past.length > this.limit) this.past.shift();
      this.current = next;
      this.future = [];
      this.group = group;
      return true;
    }

    endGroup() {
      this.group = null;
    }

    replaceCurrent(snapshot) {
      this.current = copy(snapshot);
      this.group = null;
    }

    undo() {
      this.endGroup();
      if (!this.past.length) return null;
      this.future.push(this.current);
      if (this.future.length > this.limit) this.future.shift();
      this.current = this.past.pop();
      return copy(this.current);
    }

    redo() {
      this.endGroup();
      if (!this.future.length) return null;
      this.past.push(this.current);
      if (this.past.length > this.limit) this.past.shift();
      this.current = this.future.pop();
      return copy(this.current);
    }

    get canUndo() {
      return this.past.length > 0;
    }

    get canRedo() {
      return this.future.length > 0;
    }
  }

  globalThis.CalightOfferHistory = OfferHistory;
})();
