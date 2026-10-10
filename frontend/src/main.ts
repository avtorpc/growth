import { Component, inject, signal } from '@angular/core';
import { bootstrapApplication } from '@angular/platform-browser';
import { HttpClient, provideHttpClient } from '@angular/common/http';
import { timeout } from 'rxjs';

@Component({
  selector: 'app-root',
  standalone: true,
  template: `
    <main>
      <p class="eyebrow">GROWTH · ЦВЕТОЧНЫЙ МАГАЗИН</p>
      <h1>Заказы магазина</h1>
      <p>Подготовка рабочего окружения</p>
      <section aria-labelledby="connection-title">
        <h2 id="connection-title">Подключение к системе</h2>
        <p role="status" aria-live="polite">{{ message() }}</p>
        <button (click)="check()" [disabled]="loading()">Проверить подключение</button>
      </section>
    </main>
  `,
})
class AppComponent {
  private readonly http = inject(HttpClient);
  readonly loading = signal(false);
  readonly message = signal('Проверяем подключение…');

  constructor() { this.check(); }

  check(): void {
    this.loading.set(true);
    this.message.set('Проверяем подключение…');
    this.http.get<{ status: string; database: string }>('/api/health')
      .pipe(timeout(10000))
      .subscribe({
        next: result => {
          this.message.set(result.status === 'ok' && result.database === 'ok'
            ? 'Подключение установлено. Система готова к следующему этапу.'
            : 'Система пока не готова. Повторите проверку.');
          this.loading.set(false);
        },
        error: () => {
          this.message.set('Не удалось подключиться к системе. Повторите проверку.');
          this.loading.set(false);
        },
      });
  }
}

bootstrapApplication(AppComponent, { providers: [provideHttpClient()] })
  .catch(console.error);
