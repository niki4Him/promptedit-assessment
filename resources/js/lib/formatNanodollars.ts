export function formatNanodollars(nanodollars: string): string {
    const digits = nanodollars.padStart(10, '0');
    const whole = digits.slice(0, -9);
    const fraction = digits.slice(-9).replace(/0+$/, '').padEnd(2, '0');

    return '$' + whole + '.' + fraction;
}
