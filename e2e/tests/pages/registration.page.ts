import type { Locator, Page } from '@playwright/test';

export interface RegistrationValues {
  name: string;
  email: string;
  phone: string;
  /** Label of the registration-type option, e.g. 'Paid Registration ($5.00)' or 'Free Registration'. */
  typeLabel?: string;
  safetyAcknowledged?: boolean;
  ageAcknowledged?: boolean;
}

/**
 * Event registration (/registrations/event/<id>) and the registration status page
 * (/registrations/view/<id>). The paid form's type select has no accessible name of
 * its own, so it is identified by its options, like the event form's paid select.
 * The Braintree drop-in is stubbed in the harness: submitting injects the nonce
 * `fake-valid-nonce`, so a paid submit exercises a real fake-gateway sale.
 */
export class RegistrationPage {
  constructor(private readonly page: Page) {}

  async gotoEvent(eventId: number) {
    await this.page.goto(`/registrations/event/${eventId}`);
  }

  async gotoView(id: number, editKey?: string) {
    await this.page.goto(editKey ? `/registrations/view/${id}?edit_key=${editKey}` : `/registrations/view/${id}`);
  }

  get heading(): Locator {
    return this.page.getByRole('heading', { name: 'Event Registration' });
  }

  get statusHeading(): Locator {
    return this.page.getByRole('heading', { name: 'Registration Status' });
  }

  get noSpacesNotice(): Locator {
    return this.page.getByText('No available spaces!');
  }

  get membersOnlyNotice(): Locator {
    return this.page.getByText('This event is for DMS Members only.');
  }

  private get nameInput(): Locator {
    return this.page.getByRole('textbox', { name: /^Name/ });
  }

  private get emailInput(): Locator {
    return this.page.getByRole('textbox', { name: /^Email/ });
  }

  private get phoneInput(): Locator {
    return this.page.getByRole('textbox', { name: /^Phone/ });
  }

  private get typeSelect(): Locator {
    return this.page.getByRole('combobox').filter({ has: this.page.getByRole('option', { name: /Registration/ }) });
  }

  private get safetyCheckbox(): Locator {
    return this.page.getByRole('checkbox', { name: /I acknowledge the above considerations/ });
  }

  private get ageCheckbox(): Locator {
    return this.page.getByRole('checkbox', { name: /years old or older/ });
  }

  private get submitButton(): Locator {
    return this.page.getByRole('button', { name: /^(Confirm Registration|Submit Registration for Approval)$/ });
  }

  async register(v: RegistrationValues): Promise<void> {
    await this.nameInput.fill(v.name);
    await this.emailInput.fill(v.email);
    await this.phoneInput.fill(v.phone);
    if (v.typeLabel !== undefined && (await this.typeSelect.count()) > 0) {
      await this.typeSelect.selectOption({ label: v.typeLabel });
    }
    if (v.safetyAcknowledged && (await this.safetyCheckbox.count()) > 0) {
      await this.safetyCheckbox.check();
    }
    if (v.ageAcknowledged && (await this.ageCheckbox.count()) > 0) {
      await this.ageCheckbox.check();
    }
    await Promise.all([this.page.waitForLoadState(), this.submitButton.click()]);
    await this.page.waitForLoadState();
  }

  /** The status page's cancel postLink (confirm dialog, keeps the edit_key query). */
  get cancelButton(): Locator {
    return this.page.getByRole('link', { name: 'Cancel RSVP' });
  }

  async cancelRsvp(): Promise<void> {
    this.page.once('dialog', (dialog) => dialog.accept());
    await Promise.all([
      this.page.waitForResponse((r) => r.request().method() === 'POST'),
      this.cancelButton.click(),
    ]);
    await this.page.waitForLoadState();
  }

  async alertTexts(): Promise<string[]> {
    return (await this.page.getByRole('alert').allInnerTexts()).map((t) => t.replace(/[\s]+/g, ' ').trim()).filter(Boolean);
  }

  async bodyText(): Promise<string> {
    return (await this.page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  }
  /**
   * Post the registration form with a chosen payment nonce, reusing the rendered
   * form field set (CSRF token, _Token fields, hidden defaults) so the Security
   * component accepts it. Only used for gateway-failure branches the drop-in stub
   * cannot produce (it always injects fake-valid-nonce). Returns the response HTML.
   */
  async registerWithNonce(eventId: number, v: RegistrationValues, nonce: string): Promise<string> {
    await this.gotoEvent(eventId);
    await this.nameInput.fill(v.name);
    await this.emailInput.fill(v.email);
    await this.phoneInput.fill(v.phone);
    if (v.typeLabel !== undefined && (await this.typeSelect.count()) > 0) {
      await this.typeSelect.selectOption({ label: v.typeLabel });
    }
    if (v.safetyAcknowledged && (await this.safetyCheckbox.count()) > 0) {
      await this.safetyCheckbox.check();
    }
    if (v.ageAcknowledged && (await this.ageCheckbox.count()) > 0) {
      await this.ageCheckbox.check();
    }
    const fields = await this.page.locator("#registration").evaluate((form) => {
      const out: [string, string][] = [];
      for (const el of Array.from(form.querySelectorAll("input, select, textarea"))) {
        const input = el as HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
        if (!input.name || input.disabled) continue;
        if (input instanceof HTMLInputElement && (input.type === "checkbox" || input.type === "radio")) {
          if (input.checked) out.push([input.name, input.value]);
          continue;
        }
        out.push([input.name, input.value]);
      }
      return out;
    });
    const data: Record<string, string> = {};
    for (const [name, value] of fields) data[name] = value;
    data["payment_method_nonce"] = nonce;
    const response = await this.page.request.post("/registrations/event/" + eventId, { form: data });
    return response.text();
  }
}
