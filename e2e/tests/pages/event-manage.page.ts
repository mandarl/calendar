import type { Locator, Page } from '@playwright/test';

/**
 * The host/admin panels on an event view page: the Attendance tab and the
 * AD Assignment tab. Their checkboxes carry no accessible name (a bare
 * Form->checkbox per registration row), so they are located inside their tab
 * pane by position — the documented last resort, kept inside this page object.
 * The attendance/assignment forms post to /events/attendance and
 * /events/assignments with the event id as a hidden field.
 */
export class EventManagePage {
  constructor(private readonly page: Page) {}

  async gotoView(id: number) {
    await this.page.goto(`/events/view/${id}`);
  }

  get attendanceTab(): Locator {
    return this.page.getByRole('tab', { name: 'Attendance' });
  }

  get assignmentTab(): Locator {
    return this.page.getByRole('tab', { name: 'AD Assignment' });
  }

  private attendancePane(): Locator {
    return this.page.locator('#attendance');
  }

  private assignmentPane(): Locator {
    return this.page.locator('#assignments');
  }

  /** Attendance checkboxes, in registration order (only confirmed registrations are listed). */
  attendanceCheckboxes(): Locator {
    return this.attendancePane().locator('input[type="checkbox"]');
  }

  assignmentCheckboxes(): Locator {
    return this.assignmentPane().locator('input[type="checkbox"]');
  }

  /** The checkmark shown for a registration whose AD group is already assigned. */
  assignmentCheckmarks(): Locator {
    return this.assignmentPane().locator('.glyphicon-ok');
  }

  async openAttendance(): Promise<void> {
    await this.attendanceTab.click();
  }

  async openAssignments(): Promise<void> {
    await this.assignmentTab.click();
  }

  async markAttendedAll(): Promise<void> {
    const boxes = this.attendanceCheckboxes();
    for (let i = 0; i < (await boxes.count()); i++) {
      await boxes.nth(i).check();
    }
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: 'Mark Attended' }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async assignAll(): Promise<void> {
    const boxes = this.assignmentCheckboxes();
    for (let i = 0; i < (await boxes.count()); i++) {
      await boxes.nth(i).check();
    }
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.page.getByRole('button', { name: 'Assign AD Group' }).click(),
    ]);
    await this.page.waitForLoadState();
  }

  async paneText(pane: 'attendance' | 'assignments'): Promise<string> {
    const loc = pane === 'attendance' ? this.attendancePane() : this.assignmentPane();
    return (await loc.innerText()).replace(/\s+/g, ' ').trim();
  }

  async alertTexts(): Promise<string[]> {
    return (await this.page.getByRole('alert').allInnerTexts()).map((t) => t.replace(/[\s]+/g, ' ').trim()).filter(Boolean);
  }
}
