import type { Locator, Page } from '@playwright/test';

/** Everything the "Submit Event" / "Edit Event" form can take. Unset keys keep the form default. */
export interface EventFormValues {
  title?: string;
  /** 'Class' | 'Event' */
  type?: 'Class' | 'Event';
  shortDescription?: string;
  /** Typed into the date picker, `MM/DD/YYYY h:mm A` Chicago time. */
  start?: string;
  end?: string;
  description?: string;
  /** Extra categories (the multi-select), by visible name. */
  categories?: string[];
  /** Continued dates of a multipart event, in order: [[start2, end2], [start3, end3], ...]. Ticks "Multipart Event". */
  continuedDates?: [string, string][];
  sponsored?: { name: string; email: string; phone: string } | { existing: string };
  paid?: 'Free' | 'Paid (DMS)' | 'Paid (Eventbrite)';
  cost?: number;
  eventbriteLink?: string;
  freeSpaces?: number;
  paidSpaces?: number;
  cancellationDays?: number;
  extendRegistration?: string;
  membersOnly?: boolean;
  requireApproval?: boolean;
  ageRestriction?: string;
  fulfillsPrerequisite?: string;
  requiresPrerequisite?: string;
  advisories?: string;
  room?: string;
  tools?: string[];
  setup?: string;
  teardown?: string;
  notifyOnRegistrations?: boolean;
  notifyOnCancellations?: boolean;
  honorarium?: { committee: string; payInstructor: 'Yes' | 'No' };
  files?: { slot: number; name: string; mimeType: string; buffer: Buffer; private?: boolean }[];
}

/**
 * The event form, shared by "Submit Event" (/events/add, also ?copy=<id>) and "Edit Event" (/events/edit/<id>).
 * Located by role and label only. Fields that the page reveals through JavaScript (instructor, honorarium, cost,
 * continued dates) are opened first, the way a person would.
 */
export class EventFormPage {
  constructor(private readonly page: Page) {}

  async gotoAdd() {
    await this.page.goto('/events/add');
  }

  async gotoCopy(id: number) {
    await this.page.goto(`/events/add?copy=${id}`);
  }

  async gotoEdit(id: number) {
    await this.page.goto(`/events/edit/${id}`);
  }

  // ------------------------------------------------------------------ locators
  get titleInput(): Locator { return this.page.getByRole('textbox', { name: /^Class or Event Title/ }); }
  get shortDescriptionInput(): Locator { return this.page.getByRole('textbox', { name: /^Short Description/ }); }
  get startInput(): Locator { return this.page.getByRole('textbox', { name: /^Event Start/ }); }
  get endInput(): Locator { return this.page.getByRole('textbox', { name: /^Event End/ }); }
  get descriptionInput(): Locator { return this.page.getByRole('textbox', { name: /^Description/ }); }
  private get multipartCheckbox(): Locator { return this.page.getByRole('checkbox', { name: 'Multipart Event' }); }
  private get sponsoredCheckbox(): Locator { return this.page.getByRole('checkbox', { name: 'Sponsored Event' }); }
  private get categoriesList(): Locator { return this.page.getByRole('listbox', { name: 'Categories' }); }
  private get paidSelect(): Locator {
    // The "Paid Event?" select has no accessible name of its own; identify it by its options.
    return this.page.getByRole('combobox').filter({ has: this.page.getByRole('option', { name: 'Paid (DMS)' }) });
  }
  private get costInput(): Locator { return this.page.getByRole('spinbutton', { name: /^Cost/ }); }
  private get eventbriteInput(): Locator { return this.page.getByRole('textbox', { name: /Eventbrite Link/ }); }
  private get freeSpacesInput(): Locator { return this.page.getByRole('spinbutton', { name: /^Free Spaces/ }); }
  private get paidSpacesInput(): Locator { return this.page.getByRole('spinbutton', { name: /^Paid Spaces/ }); }
  private get cancellationInput(): Locator { return this.page.getByRole('spinbutton', { name: /^Cancellation Window/ }); }
  private get extendSelect(): Locator { return this.page.getByRole('combobox', { name: 'Extend Registration' }); }
  private get membersOnlyCheckbox(): Locator { return this.page.getByRole('checkbox', { name: 'Only allow DMS members to register for this event' }); }
  private get approvalCheckbox(): Locator { return this.page.getByRole('checkbox', { name: 'Attendees Require Approval' }); }
  private get ageSelect(): Locator { return this.page.getByRole('combobox', { name: /^Age Restriction/ }); }
  private get fulfillsSelect(): Locator { return this.page.getByRole('combobox', { name: 'Fulfills Prerequisite' }); }
  private get requiresSelect(): Locator { return this.page.getByRole('combobox', { name: 'Requires Prerequisite' }); }
  private get advisoriesInput(): Locator { return this.page.getByRole('textbox', { name: 'Special Considerations and Warnings' }); }
  private get roomSelect(): Locator { return this.page.getByRole('combobox', { name: /^Room/ }); }
  private get toolsList(): Locator { return this.page.getByRole('listbox', { name: 'Tools' }); }
  private get setupSelect(): Locator { return this.page.getByRole('combobox', { name: /^Setup Time/ }); }
  private get teardownSelect(): Locator { return this.page.getByRole('combobox', { name: /^Teardown Time/ }); }
  private get notifyRegistrations(): Locator { return this.page.getByRole('checkbox', { name: 'Notify Instructor Registrations' }); }
  private get notifyCancellations(): Locator { return this.page.getByRole('checkbox', { name: 'Notify Instructor Cancellations' }); }
  private get honorariumCheckbox(): Locator { return this.page.getByRole('checkbox', { name: 'Request Honorarium' }); }
  private get committeeSelect(): Locator { return this.page.getByRole('combobox', { name: /^Committee/ }); }
  private get payInstructorSelect(): Locator { return this.page.getByRole('combobox', { name: 'Pay Instructor' }); }
  private get newInstructorName(): Locator { return this.page.getByRole('textbox', { name: 'New Instructor Name' }); }
  private get newInstructorEmail(): Locator { return this.page.getByRole('textbox', { name: 'New Instructor Email' }); }
  private get newInstructorPhone(): Locator { return this.page.getByRole('textbox', { name: 'New Instructor Phone Number' }); }
  private get existingInstructor(): Locator { return this.page.getByRole('combobox', { name: 'Existing Instructors' }); }
  private get submitButton(): Locator { return this.page.getByRole('button', { name: /^(Submit Event|Save|Update)/ }); }

  private continued(n: number, part: 'Start' | 'End'): Locator {
    const ord = ['', '', 'Second', 'Third', 'Fourth', 'Fifth'][n];
    return this.page.getByRole('textbox', { name: new RegExp(`^${ord} Date ${part}`) });
  }

  /** The red/green banner messages (flash). */
  get alerts(): Locator { return this.page.getByRole('alert'); }

  /** Whether the "Cancel Event" button of the edit form is offered. */
  get cancelEventButton(): Locator { return this.page.getByRole('link', { name: 'Cancel Event' }); }

  // ------------------------------------------------------------------ date fields
  /**
   * Type a date into a picker field the way a person does. Quirk of the current page (webroot/js/app.js, the first
   * `dp.change` handler on the start field): the FIRST value typed into an empty start field snaps to 12:00 PM, so the
   * value is typed again until the field shows what was asked for.
   */
  async typeDate(field: Locator, text: string): Promise<void> {
    for (let i = 0; i < 3; i++) {
      await field.fill(text);
      await field.press('Tab');
      if ((await field.inputValue()) === text) return;
    }
  }

  /** Put a value into a date field WITHOUT the picker (what a script posting the form would send). */
  async setDateRaw(field: 'start' | 'end' | 'second start' | 'second end', text: string): Promise<void> {
    const loc = { start: this.startInput, end: this.endInput, 'second start': this.continued(2, 'Start'), 'second end': this.continued(2, 'End') }[field];
    await loc.evaluate((el, v) => { (el as HTMLInputElement).value = v; }, text);
  }

  // ------------------------------------------------------------------ filling
  async fill(v: EventFormValues): Promise<void> {
    if (v.title !== undefined) await this.titleInput.fill(v.title);
    if (v.type) await this.page.getByRole('radio', { name: v.type, exact: true }).check();
    if (v.shortDescription !== undefined) await this.shortDescriptionInput.fill(v.shortDescription);
    if (v.start !== undefined) await this.typeDate(this.startInput, v.start);
    if (v.end !== undefined) await this.typeDate(this.endInput, v.end);
    if (v.description !== undefined) await this.descriptionInput.fill(v.description);
    for (const f of v.files ?? []) {
      await this.page.getByLabel(`File ${f.slot}`).setInputFiles({ name: f.name, mimeType: f.mimeType, buffer: f.buffer });
      if (f.private) await this.page.getByRole('checkbox', { name: 'Private' }).nth(f.slot - 1).check();
    }
    if (v.categories?.length) await this.categoriesList.selectOption(v.categories.map((label) => ({ label })));

    if (v.continuedDates?.length) {
      await this.multipartCheckbox.check();
      for (const [i, [s, e]] of v.continuedDates.entries()) {
        await this.typeDate(this.continued(i + 2, 'Start'), s);
        await this.typeDate(this.continued(i + 2, 'End'), e);
      }
    }
    if (v.sponsored) {
      await this.sponsoredCheckbox.check();
      if ('existing' in v.sponsored) {
        await this.existingInstructor.selectOption({ label: v.sponsored.existing });
      } else {
        await this.newInstructorName.fill(v.sponsored.name);
        await this.newInstructorEmail.fill(v.sponsored.email);
        await this.newInstructorPhone.fill(v.sponsored.phone);
      }
    }

    if (v.paid) await this.paidSelect.selectOption({ label: v.paid });
    if (v.cost !== undefined) await this.costInput.fill(String(v.cost));
    if (v.eventbriteLink !== undefined) await this.eventbriteInput.fill(v.eventbriteLink);
    if (v.freeSpaces !== undefined) await this.freeSpacesInput.fill(String(v.freeSpaces));
    if (v.paidSpaces !== undefined) await this.paidSpacesInput.fill(String(v.paidSpaces));
    if (v.cancellationDays !== undefined) await this.cancellationInput.fill(String(v.cancellationDays));
    if (v.extendRegistration) await this.extendSelect.selectOption({ label: v.extendRegistration });
    if (v.membersOnly !== undefined) await this.membersOnlyCheckbox.setChecked(v.membersOnly);
    if (v.requireApproval !== undefined) await this.approvalCheckbox.setChecked(v.requireApproval);
    if (v.ageRestriction) await this.ageSelect.selectOption({ label: v.ageRestriction });
    if (v.fulfillsPrerequisite) await this.fulfillsSelect.selectOption({ label: v.fulfillsPrerequisite });
    if (v.requiresPrerequisite) await this.requiresSelect.selectOption({ label: v.requiresPrerequisite });
    if (v.advisories !== undefined) await this.advisoriesInput.fill(v.advisories);
    if (v.room) await this.roomSelect.selectOption({ label: v.room });
    if (v.tools?.length) await this.toolsList.selectOption(v.tools.map((label) => ({ label })));
    if (v.setup) await this.setupSelect.selectOption({ label: v.setup });
    if (v.teardown) await this.teardownSelect.selectOption({ label: v.teardown });
    if (v.notifyOnRegistrations !== undefined) await this.notifyRegistrations.setChecked(v.notifyOnRegistrations);
    if (v.notifyOnCancellations !== undefined) await this.notifyCancellations.setChecked(v.notifyOnCancellations);
    if (v.honorarium) {
      await this.honorariumCheckbox.check();
      await this.committeeSelect.selectOption({ label: v.honorarium.committee });
      await this.payInstructorSelect.selectOption({ label: v.honorarium.payInstructor });
    }
  }

  /** Normal submit: the browser's own required-field checks apply. */
  async submit(): Promise<void> {
    await this.submitButton.click();
  }

  /**
   * Submit like a script would: switch the browser-side validation off first. Used to reach the SERVER's validation
   * (the browser's `required` attributes would otherwise stop the request).
   */
  async submitSkippingBrowserValidation(): Promise<number> {
    await this.page.getByRole('button', { name: /^(Submit Event|Save|Update)/ }).evaluate((b) => {
      (b as HTMLButtonElement).form!.noValidate = true;
    });
    const [response] = await Promise.all([this.page.waitForResponse((r) => r.request().method() === 'POST'), this.submit()]);
    await this.page.waitForLoadState();
    return response.status();
  }

  // ------------------------------------------------------------------ results
  /** Text of every flash banner currently shown. */
  async alertTexts(): Promise<string[]> {
    return (await this.alerts.allInnerTexts()).map((t) => t.replace(/[×\s]+/g, ' ').trim()).filter(Boolean);
  }

  /**
   * Validation messages: for every field the page marks as invalid, its label and the messages shown with it
   * (the static help text is included, it is part of what a person sees).
   */
  async fieldErrors(): Promise<Record<string, string[]>> {
    return this.page.locator('.has-error').evaluateAll((groups) => {
      const out: Record<string, string[]> = {};
      for (const g of groups) {
        const label = (g.querySelector('label')?.textContent ?? '').replace(/\s+/g, ' ').trim();
        out[label || '(unlabelled)'] = Array.from(g.querySelectorAll('.help-block, .error-message')).map((e) => (e.textContent ?? '').replace(/\s+/g, ' ').trim());
      }
      return out;
    });
  }

  /** Current value of the start / end field (edit form, after the page converted it for display). */
  async dates(): Promise<{ start: string; end: string }> {
    return { start: await this.startInput.inputValue(), end: await this.endInput.inputValue() };
  }
  /**
   * The edit form Cancel Event postLink (confirm dialog). Cancels the event and,
   * per EventsController::cancel, its confirmed/pending registrations (mail + refund).
   */
  async cancelEvent() {
    this.page.once("dialog", (dialog) => dialog.accept());
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === "POST"),
      this.cancelEventButton.click(),
    ]);
    await this.page.waitForLoadState();
  }
}
